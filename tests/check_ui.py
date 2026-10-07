"""Read-only UI checks; start the local PHP server on port 8080 first."""
from pathlib import Path
import tempfile
from playwright.sync_api import sync_playwright, expect

URL = 'http://127.0.0.1:8080/'
OUT = Path(tempfile.gettempdir()) / 'bitshop-ui-checks'
OUT.mkdir(exist_ok=True)

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
    page = browser.new_page(viewport={'width': 1440, 'height': 1000})
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.goto(URL + 'home.html', wait_until='networkidle')
    filters = page.locator('[data-product-filters]')
    expect(filters).to_be_hidden()
    expect(page.locator('.site-nav').get_by_role('link', name='Roulette')).to_have_count(0)
    page.get_by_role('button', name='Show filters', exact=True).click()
    expect(filters).to_be_visible()
    # Preserve an active category while collapsing and reopening the controls.
    category = filters.locator('button').last
    category.click()
    visible = page.locator('[data-product-card]:visible').count()
    summary = page.locator('[data-filter-summary]').inner_text()
    page.get_by_role('button', name='Hide filters', exact=True).click()
    expect(filters).to_be_hidden()
    expect(page.locator('[data-toggle-filters]')).to_have_attribute('aria-expanded', 'false')
    assert page.locator('[data-product-card]:visible').count() == visible
    expect(page.locator('[data-filter-summary]')).to_have_text(summary)
    page.get_by_role('button', name='Show filters', exact=True).click()
    expect(category).to_have_attribute('aria-pressed', 'true')
    page.get_by_role('button', name='Hide filters', exact=True).click()
    page.reload(wait_until='networkidle')
    expect(filters).to_be_hidden()
    page.get_by_role('button', name='Show filters', exact=True).focus()
    page.keyboard.press('Enter')
    expect(filters).to_be_visible()
    page.screenshot(path=str(OUT / 'catalogue.png'), full_page=True)

    for width in [1440, 375, 320]:
        page.set_viewport_size({'width': width, 'height': 812})
        page.goto(URL + 'payment.html', wait_until='networkidle')
        page.locator('[data-value]').first.click()
        page.locator('#cartBtn').click()
        page.locator('#cartPay').click()
        page.wait_for_function('''() => {
            const stage = document.querySelector('#pay').getBoundingClientRect();
            const header = document.querySelector('.site-header').getBoundingClientRect();
            return stage.top >= header.bottom + 15 && stage.top <= header.bottom + 25;
        }''')
        page.screenshot(path=str(OUT / f'payment-scroll-{width}.png'))
        payment_width = page.locator('.card').bounding_box()['width']
        page.evaluate('window.scrollTo(0, document.body.scrollHeight)')
        page.wait_for_function('Math.abs(document.querySelector(".site-header").getBoundingClientRect().top) < 1')
        cart = page.locator('#cartBtn').bounding_box()
        assert cart and cart['y'] >= 0 and cart['y'] + cart['height'] < 100
        page.locator('#cartBtn').click()
        expect(page.locator('#cartPanel')).to_be_visible()
        panel = page.locator('#cartPanel').bounding_box()
        assert panel['y'] >= cart['y'] + cart['height']
        assert panel['y'] + panel['height'] <= 812
        page.screenshot(path=str(OUT / f'cart-{width}.png'))
        page.keyboard.press('Escape')
        expect(page.locator('#cartBtn')).to_be_focused()
        expect(page.get_by_label('Contact email', exact=True)).to_be_visible()
        expect(page.locator('#paymentDetails')).to_be_hidden()
        page.screenshot(path=str(OUT / f'checkout-{width}.png'), full_page=True)
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')

    page.goto(URL + 'admin.php', wait_until='networkidle')
    page.get_by_label('Mot de passe', exact=True).fill('admin')
    page.get_by_role('button', name='Se connecter', exact=True).click()
    expect(page.locator('.greeting, .avatar')).to_have_count(0)
    expect(page.locator('.admin-header').get_by_role('link', name='Voir la boutique')).to_be_visible()
    for width in [1440, 375, 320]:
        page.set_viewport_size({'width': width, 'height': 900})
        for tab in ['products', 'payment', 'orders', 'roulette']:
            page.goto(URL + 'admin.php?tab=' + tab, wait_until='networkidle')
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), (width, tab)
            page.screenshot(path=str(OUT / f'admin-{tab}-{width}.png'), full_page=True)
    assert not errors, errors
    print(f'Filters, sticky cart, order form and responsive admin: OK. Screenshots: {OUT}')
    browser.close()
