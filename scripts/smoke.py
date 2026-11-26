#!/usr/bin/env python3
import concurrent.futures
import datetime as dt
import json
import os
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = os.getenv('API_URL', 'http://localhost:8000/api').rstrip('/')
RUN = str(uuid.uuid4())
PAYLOAD = {'recipient': '+905551234567', 'channel': 'sms', 'content': 'Assessment demo', 'priority': 'high'}

def call(path, method='GET', body=None, key=None, expected=200):
    headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
    if key: headers['Idempotency-Key'] = RUN + '-' + key
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode() if body is not None else None, headers=headers, method=method)
    try:
        response = urllib.request.urlopen(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        data = json.load(response)
        assert response.status == expected, (path, response.status, expected, data)
        assert response.headers.get('X-Correlation-ID') or response.status >= 400, 'Missing correlation ID'
        return data

def create(key, **changes):
    return call('/notifications', 'POST', PAYLOAD | changes, key, 202)

def terminal(id, target, timeout=90):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        n = call('/notifications/' + id)
        if n['status'] == target: return n
        if n['status'] in ('accepted', 'failed', 'cancelled', 'unknown'):
            raise AssertionError((id, target, n['status'], n.get('error')))
        time.sleep(.5)
    raise AssertionError((id, 'timeout', target))

def future():
    return (dt.datetime.now(dt.timezone.utc) + dt.timedelta(days=7)).isoformat()

if __name__ == '__main__':
    assert call('/health')['status'] == 'ok'
    print('PASS health', flush=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(lambda _: create('same'), range(12)))
    ids = {r['notifications'][0]['id'] for r in results}
    assert len(ids) == 1 and len({r['batch_id'] for r in results}) == 1
    call('/notifications', 'POST', PAYLOAD | {'content': 'different'}, 'same', 409)
    assert terminal(ids.pop(), 'accepted')['attempts'] == 1
    print('PASS concurrent idempotency and real worker/provider delivery', flush=True)
    for channel, recipient, priority in [('email','test@example.test','normal'),('push','demo-device','low')]:
        n = create(channel, channel=channel, recipient=recipient, priority=priority)
        assert terminal(n['notifications'][0]['id'], 'accepted')['attempts'] == 1
    print('PASS email, push, all priority queues', flush=True)
    rejected = create('reject', content='mock:reject')['notifications'][0]['id']
    assert terminal(rejected, 'failed')['attempts'] == 1
    uncertain = create('unknown', content='mock:unknown')['notifications'][0]['id']
    assert terminal(uncertain, 'unknown')['attempts'] == 1
    retry = create('retry', content='mock:retry')['notifications'][0]['id']
    print('PASS failure and ambiguous result; retry submitted', flush=True)
    scheduled = create('scheduled', scheduled_at=future())['notifications'][0]['id']
    assert call('/notifications/'+scheduled)['status'] == 'pending'
    call('/notifications/'+scheduled+'/cancel','POST')
    assert terminal(scheduled,'cancelled')['attempts'] == 0
    call('/notifications/'+scheduled+'/cancel','POST',expected=409)
    call('/notifications','POST',PAYLOAD | {'content':'x'*161},'invalid',422)
    batch = {'notifications': [PAYLOAD | {'scheduled_at':future()} for _ in range(1000)]}
    result = call('/notifications/batch','POST',batch,'batch',202)
    assert len(result['notifications']) == 1000
    assert call('/batches/'+result['batch_id'])['total'] == 1000
    call('/notifications/batch','POST',{'notifications': batch['notifications']+[PAYLOAD]},'oversized',422)
    today = dt.datetime.now(dt.timezone.utc).date().isoformat()
    data = call('/notifications?'+urllib.parse.urlencode({'channel':'sms','status':'pending','from':today,'to':today,'per_page':10}))
    assert len(data['data']) == 10 and data['total'] >= 1000
    assert all(n['channel']=='sms' and n['status']=='pending' for n in data['data'])
    print('PASS scheduling, cancellation, validation, batch 1000/1001, filters and pagination', flush=True)
    metrics = call('/metrics')
    assert all(p in metrics['queue_depth'] for p in ('high','normal','low'))
    assert metrics['success_rate'] is not None and metrics['failure_rate'] is not None
    assert metrics['average_latency_ms'] is not None
    assert terminal(retry,'failed',timeout=120)['attempts'] == 5
    print('PASS metrics and retry exhaustion; all smoke assertions passed', flush=True)
    print('Note: 1000 scheduled test rows remain; reset disposable test volumes before switching to webhook.site.')
