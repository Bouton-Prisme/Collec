"""Order lifecycle and UI checks in a disposable copy. No real payments or emails."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parent.parent
OUT = Path(tempfile.gettempdir()) / 'bitshop-order-checks'
OUT.mkdir(exist_ok=True)
GUARD = '<?php http_response_code(404); exit; ?>\n'

def run():
    with tempfile.TemporaryDirectory(prefix='bitshop-orders-') as temp:
        root = Path(temp)
        for file in ROOT.iterdir():
            if file.is_file() and file.suffix in {'.php', '.html', '.css', '.js', '.json', '.png'}:
                shutil.copy2(file, root / file.name)
        for folder in ['vendor', 'media']:
            shutil.copytree(ROOT / folder, root / folder)
        (root / 'config').mkdir()
        php = shutil.which('php') or 'C:/php/php.exe'
        defaults = subprocess.check_output([php, '-r', f"require '{(root / 'payment-settings.php').as_posix()}'; echo json_encode(payment_defaults());"], text=True)
        settings = json.loads(defaults)
        def save_settings():
            (root / 'config/payment.local.php').write_bytes((GUARD + json.dumps(settings)).encode('utf-8'))
        save_settings()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        url = f'http://127.0.0.1:{port}/'
        with (OUT / 'php.log').open('w') as log:
            server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
            try:
                with sync_playwright() as p:
                    browser = p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
                    context = browser.new_context(viewport={'width': 1440, 'height': 1000}, permissions=['clipboard-read', 'clipboard-write'])
                    for attempt in range(30):
                        try:
                            if context.request.get(url + 'payment-config.php').ok: break
                        except Exception: time.sleep(.1)
                    external_posts = []
                    context.on('request', lambda request: external_posts.append(request.url) if request.method == 'POST' and not request.url.startswith(url) else None)
                    page = context.new_page(); errors = []
                    page.on('pageerror', lambda error: errors.append(str(error)))
                    page.goto(url + 'payment.html?value=3&asset=ETH#pay', wait_until='domcontentloaded')
                    expect(page.locator('#paymentDetails')).to_be_hidden()
                    expect(page.locator('#payAmount')).to_contain_text('15.00 USD')
                    page.get_by_label('Contact email', exact=True).fill('buyer@example.invalid')
                    page.locator('#proofLink').click(); page.wait_for_url('**/order.php?token=*')
                    demo_url = page.url
                    expect(page.locator('.demo-banner')).to_be_visible()
                    expect(page.locator('#order-amount')).to_have_value('0.005')
                    page.locator('#copy-tracking').click()
                    assert page.evaluate('navigator.clipboard.readText()') == demo_url
                    page.get_by_label('Transaction ID', exact=True).fill('DEMO-REFERENCE')
                    page.get_by_role('button', name='Save demo reference', exact=True).click()
                    expect(page.locator('.status-badge')).to_contain_text('Reference received')
                    demo_id = page.locator('h1').inner_text()
                    def screenshots(target, name):
                        for width in [1440, 375, 320]:
                            target.set_viewport_size({'width': width, 'height': 950})
                            target.screenshot(path=str(OUT / f'{name}-{width}.png'), full_page=True)
                            assert target.evaluate('document.documentElement.scrollWidth <= innerWidth'), (name, width, target.locator('body *').evaluate_all('(els) => els.filter(e => e.getBoundingClientRect().right > innerWidth).map(e => [e.tagName,e.className,e.getBoundingClientRect().width]).slice(0,12)'))
                    screenshots(page, 'demo-review')
                    anonymous = browser.new_context()
                    assert anonymous.request.get(demo_url).ok
                    assert anonymous.request.get(url + 'order.php?token=' + '0'*64).status == 404
                    assert anonymous.request.get(url + 'order.php?id=' + demo_id).status == 404
                    assert anonymous.request.get(url + 'config/orders.local.php').status == 404
                    assert anonymous.request.post(url + 'checkout.php', form={'csrf': ''}).status == 403
                    assert 'Session expired' in anonymous.request.post(demo_url, form={'csrf': '', 'txid': 'FORGED'}).text()
                    assert anonymous.request.post(url + 'admin.php', form={'action': 'anything'}).status == 401
                    assert 'no-store' in anonymous.request.get(demo_url).headers['cache-control']
                    assert 'FORGED' not in anonymous.request.get(demo_url).text()
                    settings.update(mode='manual', rate_source='manual', default_asset='ETH', formspree_id='')
                    for symbol, asset in settings['assets'].items(): asset['enabled'] = symbol == 'ETH'
                    settings['assets']['ETH'].update(address='0x' + '1'*40, manual_rate=3000)
                    save_settings()
                    assert context.request.get(url + 'payment-config.php').ok
                    csrf = context.request.get(url + 'checkout.php').json()['csrf']
                    base = {'csrf': csrf, 'product_id': '3', 'qty': '1', 'asset': 'ETH', 'email': 'manual@example.invalid'}
                    seq = 0
                    def create(**extra):
                        nonlocal seq
                        seq += 1
                        return context.request.post(url + 'checkout.php', form={**base, 'request_id': f'{seq:032x}', **extra})
                    response = create(total_usd='0.01', amount='0.000001'); assert response.ok, response.text()
                    manual_url = url + response.json()['url']
                    assert context.request.post(url + 'checkout.php', form={**base, 'request_id': f'{seq:032x}'}).json()['url'] == response.json()['url']
                    page.goto(manual_url)
                    expect(page.locator('#order-amount')).to_have_value('0.005')
                    expect(page.locator('.order-recap')).to_contain_text('15.00 USD')
                    expect(page.locator('#open-wallet')).to_have_attribute('href', 'ethereum:0x' + '1'*40 + '@1?value=5000000000000000')
                    expect(page.locator('#order-qr canvas')).to_have_count(1)
                    screenshots(page, 'manual-payment')
                    for extra in [{'qty': '0'}, {'product_id': 'missing'}, {'asset': 'BTC'}, {'email': 'bad'}]: assert create(**extra).status == 400
                    assert create(csrf='bad').status == 403
                    settings['assets']['ETH'].update(address='0x' + '2'*40, manual_rate=6000); save_settings()
                    page.reload()
                    expect(page.locator('#order-address')).to_have_value('0x' + '1'*40)
                    expect(page.locator('#order-amount')).to_have_value('0.005')
                    store_file = root / 'config/orders.local.php'
                    records = json.loads(store_file.read_text(encoding='utf-8')[len(GUARD):])
                    manual_id = page.locator('h1').inner_text()
                    records[manual_id]['expires_at'] = int(time.time()) - 1
                    store_file.write_bytes((GUARD + json.dumps(records)).encode('utf-8'))
                    page.reload()
                    expect(page.locator('.status-badge')).to_have_text('Payment window expired')
                    expect(page.locator('#order-address')).to_have_count(0)
                    page.locator('#txid').fill('invalid'); page.get_by_role('button', name='Submit for manual review', exact=True).click()
                    expect(page.get_by_role('alert')).to_contain_text('complete transaction ID')
                    txid = '0x' + 'a'*64
                    page.locator('#txid').fill(txid); page.get_by_role('button', name='Submit for manual review', exact=True).click()
                    expect(page.locator('.status-badge')).to_contain_text('Reference received')
                    second = create(); assert second.ok
                    page.goto(url + second.json()['url']); page.locator('#txid').fill(txid)
                    page.get_by_role('button', name='Submit for manual review', exact=True).click()
                    expect(page.get_by_role('alert')).to_contain_text('already attached')
                    admin = context.new_page(); admin.goto(url + 'admin.php')
                    admin.locator('#password').fill('admin'); admin.get_by_role('button', name='Se connecter').click()
                    admin.get_by_role('link', name='Commandes', exact=True).click()
                    before_admin = store_file.read_bytes()
                    bad_update = context.request.post(url + 'admin.php?tab=orders', form={'save_order': '1', 'order_id': manual_id, 'order_status': 'paid', 'verified': '1'})
                    assert 'Session du formulaire' in bad_update.text()
                    assert store_file.read_bytes() == before_admin
                    def card(): return admin.locator('details').filter(has_text=manual_id)
                    card().locator('summary').click(); card().locator('[name="order_status"]').select_option('paid')
                    card().get_by_role('button', name='Enregistrer la commande').click()
                    expect(admin.locator('.notice.error')).to_contain_text('Confirmez')
                    for status, note in [('paid', 'Payment received. Delivery is being prepared.'), ('delivered', 'Test: delivery handled separately by the shop.')]:
                        card().locator('summary').click(); card().locator('[name="order_status"]').select_option(status)
                        card().locator('[name="verified"]').check(); card().locator('[name="public_note"]').fill(note)
                        card().get_by_role('button', name='Enregistrer la commande').click()
                        page.goto(manual_url)
                        expect(page.locator('.status-badge')).to_have_text('Payment verified' if status == 'paid' else 'Delivery completed')
                        expect(page.locator('.merchant-note')).to_contain_text(note)
                    expect(page.locator('.reference-form')).to_have_count(0)
                    card().locator('summary').click(); screenshots(admin, 'admin-orders')
                    settings['enabled'] = False; save_settings(); assert create().status == 400
                    page.goto(manual_url); expect(page.locator('.status-badge')).to_have_text('Delivery completed')
                    store_file.write_text('corrupt', encoding='utf-8')
                    assert context.request.get(manual_url).status == 503
                    assert not errors, errors
                    assert not external_posts, external_posts
                    browser.close()
                    print('PASS: server prices, retries, private links, CSRF, manual review, expiry, duplicate TXID, admin validation, delivery status, mobile, storage failure; no external POST.')
                    print(f'Screenshots: {OUT}')
            finally:
                server.terminate(); server.wait(timeout=10)

if __name__ == '__main__': run()
