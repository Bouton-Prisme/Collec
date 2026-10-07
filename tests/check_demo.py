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
    # Removing an optional notice must never prevent the catalogue from rendering.
    home_without_notice = __import__('re').sub(r'<p data-payment-notice\b[^>]*>.*?</p>', '', (ROOT / 'home.html').read_text(encoding='utf-8'))
    page.route('**/home.html', lambda route: route.fulfill(content_type='text/html', body=home_without_notice))
    for settings_status, settings in [(200, {'enabled': True}), (200, {'enabled': False}), (503, {})]:
        page.route('**/payment-config.php', lambda route: route.fulfill(status=settings_status, json=settings))
        page.goto(URL + 'home.html', wait_until='networkidle')
        expect(page.locator('[data-product-card]')).to_have_count(6)
        page.unroute('**/payment-config.php')
    page.unroute('**/home.html')
    page.goto(URL + 'home.html', wait_until='networkidle')
    expect(page.locator('[data-product-card]')).to_have_count(6)
    assert page.locator('[data-product-card] h2').all_text_contents() == ['Starter Pack', 'Bronze Pack', 'Silver Pack', 'Gold Pack', 'Ruby Pack', 'Diamond Pack']
    assert page.locator('[data-product-card] img').evaluate_all('(images) => images.every(img => img.complete && img.naturalWidth > 0)')
    page.screenshot(path=str(OUT / 'home-desktop.png'), full_page=True)
    page.locator('[data-product-card="7"] .carousel-slide').click()
    expect(page.locator('[data-carousel-modal]')).to_have_class(__import__('re').compile('is-active'))
    page.keyboard.press('Escape')
    page.locator('[data-product-card="7"]').get_by_role('link', name='Select', exact=True).click()
    expect(page.locator('#payTitle')).to_have_text('Starter Pack')
    expect(page.locator('#payAmount')).to_contain_text('5.00 USD')
    expect(page.locator('#paymentDetails')).to_be_hidden()
    expect(page.locator('.amount-media img')).to_have_count(6)
    assert page.locator('.amount-media img').evaluate_all('(images) => images.every(img => img.complete && img.naturalWidth > 0)')
    page.get_by_text('Ethereum', exact=True).click()
    expect(page.locator('[name="payment-currency"][value="ETH"]')).to_be_checked()
    expect(page.locator('#payTitle')).to_have_text('Starter Pack')
    page.get_by_text('Monero', exact=True).click()
    expect(page.locator('[name="payment-currency"][value="XMR"]')).to_be_checked()
    expect(page.locator('#payTitle')).to_have_text('Starter Pack')
    page.locator('[data-value="6"]').click()
    expect(page.locator('#payAmount')).to_contain_text('75.00 USD')
    page.screenshot(path=str(OUT / 'payment-desktop.png'), full_page=True)
    expect(page.get_by_label('Contact email', exact=True)).to_be_visible()
    # Persistent checkout lifecycle runs in the isolated check_orders.py suite.
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
    expect(page.locator('#resume-checkout')).to_be_visible()
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
    expect(page.locator('#resume-checkout')).to_be_visible()
    print(json.dumps({'errors': errors, 'screenshots': str(OUT)}, indent=2), flush=True)
    assert not errors, errors
    browser.close()
