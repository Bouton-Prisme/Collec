from pathlib import Path
from playwright.sync_api import sync_playwright, expect
import json
import tempfile

ROOT = Path(__file__).resolve().parent.parent
FILE = ROOT / 'config/payment.local.php'
ORIGINAL = FILE.read_bytes() if FILE.exists() else None
URL = 'http://127.0.0.1:8080/'
OUT = Path(tempfile.gettempdir()) / 'bitshop-demo-checks'
OUT.mkdir(exist_ok=True)

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
    context = browser.new_context(viewport={'width': 1280, 'height': 900})
    admin = context.new_page()
    shop = context.new_page()
    errors = []
    for page in [admin, shop]:
        page.on('pageerror', lambda error: errors.append(str(error)))
    external_posts = []
    def stop_external(route):
        if route.request.method == 'POST':
            external_posts.append(route.request.url)
        route.abort()
    context.route('https://formspree.io/**', stop_external)
    def field(name):
        return admin.locator(f'[name="payment[{name}]"]')
    def asset(symbol, name):
        return admin.locator(f'[name="payment[assets][{symbol}][{name}]"]')
    def save():
        admin.get_by_role('button', name='Enregistrer les paiements', exact=True).click()
        expect(admin.get_by_role('status')).to_have_text('Configuration de paiement enregistrée.')
    try:
        admin.goto(URL + 'admin.php')
        admin.get_by_label('Mot de passe', exact=True).fill('admin')
        admin.get_by_role('button', name='Se connecter', exact=True).click()
        admin.get_by_role('link', name='Payments', exact=True).click()
        admin.screenshot(path=str(OUT / 'payment-settings-desktop.png'), full_page=True)
        field('mode').select_option('demo')
        field('enabled').check()
        asset('BTC', 'enabled').uncheck()
        asset('XMR', 'enabled').uncheck()
        asset('ETH', 'enabled').check()
        field('default_asset').select_option('ETH')
        field('invoice_minutes').fill('1')
        field('support_email').fill('demo-support@example.invalid')
        field('instructions').fill('Use the Ethereum mainnet network only.')
        field('processing_message').fill('Manual review within one working day.')
        asset('ETH', 'account_label').fill('Private treasury label')
        asset('ETH', 'address').fill('0x1111111111111111111111111111111111111111')
        asset('ETH', 'manual_rate').fill('2500')
        save()
        settings = context.request.get(URL + 'payment-config.php').json()
        assert list(settings['assets']) == ['ETH']
        assert 'Private treasury label' not in json.dumps(settings)
        assert settings['assets']['ETH']['address'].startswith('0xEXEMPLE')
        assert context.request.get(URL + 'config/payment.local.php').status == 404
        assert context.request.get(URL + 'admin-payment.php').status == 404
        saved_bytes = FILE.read_bytes()
        response = context.request.post(URL + 'admin.php?tab=payment', form={'save_payment': '1', 'payment[enabled]': '0'})
        assert 'Session du formulaire' in response.text()
        assert FILE.read_bytes() == saved_bytes
        response = context.request.post(URL + 'admin.php?tab=payment', form={'password': 'wrong', 'save_payment': '1', 'payment[enabled]': '0'})
        assert 'Session du formulaire' in response.text()
        assert FILE.read_bytes() == saved_bytes
        shop.goto(URL + 'payment.html?value=7&asset=BTC')
        expect(shop.locator('#currency option')).to_have_count(1)
        expect(shop.locator('#currency')).to_have_value('ETH')
        expect(shop.locator('#payAmount')).to_contain_text('5.00 USD')
        expect(shop.locator('#paymentDetails')).to_be_hidden()
        expect(shop.locator('#paymentNetwork')).to_have_text('Ethereum mainnet')
        expect(shop.locator('#paymentSupport')).to_have_attribute('href', 'mailto:demo-support@example.invalid')
        # Order rates, expiry and reference submission are covered in check_orders.py.
        # Invalid configuration cannot overwrite the saved version.
        before = FILE.read_bytes()
        field('default_asset').select_option('BTC')
        admin.get_by_role('button', name='Enregistrer les paiements', exact=True).click()
        expect(admin.get_by_text('Activez au moins une crypto et choisissez une crypto active par défaut.')).to_be_visible()
        assert FILE.read_bytes() == before
        admin.reload()
        asset('ETH', 'address').fill('not-an-address')
        admin.get_by_role('button', name='Enregistrer les paiements', exact=True).click()
        expect(admin.get_by_text('ETH : format d’adresse incompatible avec le réseau principal.')).to_be_visible()
        assert FILE.read_bytes() == before
        admin.reload()
        field('mode').select_option('manual')
        field('rate_source').select_option('manual')
        save()
        settings = context.request.get(URL + 'payment-config.php').json()
        assert settings['mode'] == 'manual' and not settings['form_endpoint']
        expect(admin.locator('[name="payment[formspree_id]"]')).to_have_count(0)
        shop.goto(URL + 'payment.html?value=7')
        expect(shop.locator('#checkout-delay')).to_have_text('Manual review within one working day.')
        expect(shop.locator('#paymentDetails')).to_be_hidden()
        # Pause checkout, including already-known direct order URLs.
        field('enabled').uncheck()
        save()
        shop.goto(URL + 'payment.html?value=7')
        expect(shop.locator('#paymentAvailability')).to_contain_text('temporarily unavailable')
        expect(shop.locator('#proofLink')).to_be_hidden()
        shop.goto(URL + 'TYP.html?value=7&asset=ETH')
        expect(shop.locator('#resume-checkout')).to_be_visible()
        for width in [375, 320]:
            admin.set_viewport_size({'width': width, 'height': 812})
            admin.goto(URL + 'admin.php?tab=payment')
            assert admin.evaluate('document.documentElement.scrollWidth <= innerWidth'), admin.locator('body *').evaluate_all('(nodes) => nodes.filter(n => n.getBoundingClientRect().right > innerWidth).map(n => ({tag:n.tagName,cls:n.className,width:n.getBoundingClientRect().width})).slice(0,15)')
        admin.screenshot(path=str(OUT / 'payment-settings-mobile.png'), full_page=True)
        # A corrupt file must not silently re-enable payments using defaults.
        FILE.write_text('corrupt', encoding='utf-8')
        assert context.request.get(URL + 'payment-config.php').status == 503
        shop.goto(URL + 'payment.html?value=7')
        expect(shop.locator('#proofLink')).to_be_hidden()
        expect(shop.locator('#paymentAvailability')).to_contain_text('could not be loaded')
        assert not errors, errors
        assert not external_posts, external_posts
        print('PASS: settings save, active assets/default, wallet privacy, CSRF, validation, manual mode without Formspree, pause, corruption, mobile; no external submission.', flush=True)
    finally:
        if ORIGINAL is None:
            FILE.unlink(missing_ok=True)
        else:
            FILE.write_bytes(ORIGINAL)
        browser.close()
