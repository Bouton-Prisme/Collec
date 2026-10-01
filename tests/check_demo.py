from pathlib import Path
from playwright.sync_api import sync_playwright, expect
import json
import tempfile

ROOT = Path(__file__).resolve().parent.parent
OUT = Path(tempfile.gettempdir()) / 'bitshop-demo-checks'
OUT.mkdir(exist_ok=True)
URL = 'http://127.0.0.1:8080/'

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.goto(URL + 'home.html', wait_until='networkidle')
    expect(page.locator('[data-product-card]')).to_have_count(6)
    assert page.locator('[data-product-card] h2').all_text_contents() == ['Starter Pack', 'Bronze Pack', 'Silver Pack', 'Gold Pack', 'Ruby Pack', 'Diamond Pack']
    assert page.locator('[data-product-card] img').evaluate_all('(images) => images.every(img => img.complete && img.naturalWidth > 0)')
    page.screenshot(path=str(OUT / 'home-desktop.png'), full_page=True)
    page.locator('[data-product-card="7"] .carousel-slide').click()
    expect(page.locator('[data-carousel-modal]')).to_have_class(__import__('re').compile('is-active'))
    page.keyboard.press('Escape')
    page.locator('[data-product-card="7"]').get_by_role('link', name='Select', exact=True).click()
    expect(page.locator('#payTitle')).to_have_text('Pay Starter Pack (BTC)')
    expect(page.locator('#payAmount')).to_contain_text('5.00 USD')
    expect(page.locator('#payQR canvas')).to_have_count(1)
    page.locator('#currency').select_option('ETH')
    expect(page.locator('#payTitle')).to_have_text('Pay Starter Pack (ETH)')
    page.locator('#currency').select_option('XMR')
    expect(page.locator('#payTitle')).to_have_text('Pay Starter Pack (XMR)')
    context.grant_permissions(['clipboard-read', 'clipboard-write'])
    page.locator('#copyAmt').click()
    expect(page.locator('#copyAmtStatus')).to_have_text('Copied.')
    assert page.evaluate('navigator.clipboard.readText()') == page.locator('#copyAmt').inner_text()
    page.locator('[data-value="6"]').click()
    expect(page.locator('#payAmount')).to_contain_text('75.00 USD')
    page.screenshot(path=str(OUT / 'payment-desktop.png'), full_page=True)
    page.locator('#proofLink').click()
    expect(page.locator('#order-summary-content')).to_contain_text('Diamond Pack')
    expect(page.locator('#order-summary-content')).to_contain_text('75.00 USD')
    expect(page.locator('#product_name')).to_have_value('Diamond Pack')
    expect(page.locator('#currency')).to_have_value('XMR')
    page.locator('[name="buyer_email"]').fill('demo@example.invalid')
    page.locator('#txid').fill('DEMO-TRANSACTION-001')
    sent = []
    page.on('request', lambda request: sent.append(request.url) if request.method == 'POST' else None)
    page.get_by_role('button', name='Simulate submission').click()
    expect(page.locator('#proof-status')).to_contain_text('Demo complete')
    assert not sent, sent
    page.screenshot(path=str(OUT / 'order-desktop.png'), full_page=True)
    page.goto(URL + 'payment.html')
    expect(page.locator('#payTitle')).to_contain_text('Diamond Pack')
    page.locator('#cartBtn').click()
    page.get_by_role('button', name='Remove', exact=True).click()
    expect(page.locator('#proofLink')).to_be_hidden()
    expect(page.locator('#paybox')).to_be_hidden()
    page.get_by_role('button', name='Cart', exact=True).click()
    page.locator('[data-value="3"]').click()
    expect(page.locator('#payTitle')).to_contain_text('Silver Pack')
    page.goto(URL + 'roulette.html')
    roulette_media = json.loads((ROOT / 'products.json').read_text(encoding='utf-8'))['roulette']['media']
    expect(page.locator('[data-coverflow-card]')).to_have_count(len(roulette_media) or 6)
    page.locator('[data-spin-button]').click()
    expect(page.locator('[data-spin-button]')).to_be_enabled(timeout=10000)
    expect(page.locator('[data-roulette-filename]')).to_have_class(__import__('re').compile('is-visible'))
    for width in [375, 320]:
        page.set_viewport_size({'width': width, 'height': 812})
        for route in ['home.html', 'payment.html?value=4', 'TYP.html?value=4&asset=BTC', 'roulette.html', 'support.html', 'privacy.html', 'terms.html']:
            page.goto(URL + route, wait_until='networkidle')
            dimensions = page.evaluate('({scroll: document.documentElement.scrollWidth, width: innerWidth})')
            assert dimensions['scroll'] <= dimensions['width'], (route, width, dimensions)
            if width == 375:
                page.screenshot(path=str(OUT / (route.split('?')[0] + '-mobile.png')), full_page=True)
    page.goto(URL + 'payment.html?value=missing&asset=invalid')
    expect(page.locator('#currency')).to_have_value('BTC')
    page.goto(URL + 'TYP.html?value=missing')
    expect(page.locator('#order-summary-content')).to_contain_text('No product selected')
    expect(page.locator('[type="submit"]')).to_be_disabled()
    page.route('**/products.json?*', lambda route: route.fulfill(json={'products': {}, 'demoMode': True}))
    page.goto(URL + 'home.html')
    expect(page.locator('[data-products-grid]')).to_have_text('No products are available yet.')
    page.goto(URL + 'payment.html')
    expect(page.locator('#proofLink')).to_be_hidden()
    page.unroute('**/products.json?*')
    page.route('**/products.json?*', lambda route: route.fulfill(status=503, body='Unavailable'))
    page.goto(URL + 'home.html')
    expect(page.locator('[data-products-grid]')).to_contain_text('could not be loaded')
    page.goto(URL + 'TYP.html')
    expect(page.locator('[type="submit"]')).to_be_disabled()
    expect(page.locator('#order-summary-content')).to_contain_text('could not be loaded')
    print(json.dumps({'errors': errors, 'screenshots': str(OUT)}, indent=2), flush=True)
    assert not errors, errors
    browser.close()
