from pathlib import Path
from playwright.sync_api import sync_playwright, expect
import json
import tempfile

ROOT = Path(__file__).resolve().parent.parent
URL = 'http://127.0.0.1:8080/'
OUT = Path(tempfile.gettempdir()) / 'bitshop-demo-checks'
catalogue = ROOT / 'products.json'
original = catalogue.read_bytes()

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
    context = browser.new_context(viewport={'width': 1280, 'height': 900})
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    try:
        page.goto(URL + 'admin.php')
        page.get_by_label('Password', exact=True).fill('wrong-password')
        page.get_by_role('button', name='Login', exact=True).click()
        expect(page.get_by_text('Wrong password.')).to_be_visible()
        page.get_by_label('Password', exact=True).fill('admin')
        page.get_by_role('button', name='Login', exact=True).click()
        expect(page.locator('[name="products[7][sort_order]"]')).to_have_value('1')
        page.locator('[name="products[5][sort_order]"]').fill('1')
        page.locator('[name="products[7][sort_order]"]').fill('2')
        page.get_by_role('button', name='Save products', exact=True).click()
        expect(page.get_by_text('Products saved.')).to_be_visible()
        saved = json.loads(catalogue.read_text(encoding='utf-8'))
        assert saved['products']['5']['sort_order'] == 1
        assert saved['roulette'] == json.loads(original)['roulette']
        assert set(saved['products']) == set(json.loads(original)['products'])
        # Equal display order falls back to ID, without changing the IDs.
        for route, selector, attribute in [('home.html', '[data-product-card]', 'data-product-card'), ('payment.html', '[data-value]', 'data-value')]:
            page.goto(URL + route)
            expect(page.locator(selector)).to_have_count(6)
            assert page.locator(selector).evaluate_all(f'(nodes) => nodes.map(n => n.getAttribute("{attribute}"))') == ['5', '2', '7', '3', '4', '6']
        page.goto(URL + 'admin.php')
        expect(page.locator('[name="products[5][sort_order]"]')).to_have_value('1')
        # POST directly to exercise server validation, bypassing HTML constraints.
        response = context.request.post(URL + 'admin.php', form={
            'csrf_token': page.locator('[name="csrf_token"]').first.input_value(),
            'products[5][name]': 'Invalid order', 'products[5][usd]': '5', 'products[5][sort_order]': '-3'
        })
        assert 'display order between 1 and 1000000' in response.text()
        assert json.loads(catalogue.read_text(encoding='utf-8')) == saved
        page.locator('[name="new_product[name]"]').fill('Temporary gallery test')
        page.locator('[name="new_product[usd]"]').fill('12.50')
        page.locator('[name="new_product[sort_order]"]').fill('8')
        page.locator('[data-target="new-product-media"]').click()
        page.locator('[data-library-search]').fill('media/packs/Pack')
        page.locator('[data-library-checkbox][value="media/packs/Pack1.png"]').check()
        page.locator('[data-library-checkbox][value="media/packs/Pack2.png"]').check()
        page.locator('[data-apply-library]').click()
        page.get_by_role('button', name='Add product', exact=True).click()
        expect(page.get_by_text('Product added.')).to_be_visible()
        new_data = json.loads(catalogue.read_text(encoding='utf-8'))
        new_id = next(key for key in new_data['products'] if key not in saved['products'])
        assert len(new_data['products'][new_id]['media']) == 2
        page.goto(URL + 'home.html')
        card = page.locator(f'[data-product-card="{new_id}"]')
        expect(card.locator('[data-carousel]')).to_have_attribute('data-multi', 'true')
        card.locator('[data-carousel-next]').click()
        expect(card.locator('.carousel-slide.is-active img')).to_have_attribute('src', 'media/packs/Pack2.png')
        page.goto(URL + 'admin.php')
        page.on('dialog', lambda dialog: dialog.accept())
        page.locator(f'[name="delete_product"][value="{new_id}"]').click()
        expect(page.get_by_text('Product deleted.')).to_be_visible()
        assert new_id not in json.loads(catalogue.read_text(encoding='utf-8'))['products']
        page.goto(URL + 'admin.php?tab=roulette')
        page.get_by_role('button', name='Save roulette', exact=True).click()
        assert json.loads(catalogue.read_text(encoding='utf-8'))['roulette'] == saved['roulette']
        page.get_by_role('link', name='Logout', exact=True).click()
        expect(page.get_by_role('button', name='Login', exact=True)).to_be_visible()
        unauthorized = context.request.post(URL + 'admin.php', form={'delete_product': '7'})
        assert 'Login' in unauthorized.text()
        assert '7' in json.loads(catalogue.read_text(encoding='utf-8'))['products']
        page.goto(URL)
        expect(page).to_have_url(URL + 'home.html')
        assert not errors, errors
        print('PASS: login/logout, authorization, ordering + ties, persistence, invalid order rejection, create/delete, image library, gallery, roulette save, root URL', flush=True)
    finally:
        catalogue.write_bytes(original)
        browser.close()
