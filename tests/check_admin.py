from pathlib import Path
from playwright.sync_api import sync_playwright, expect
import json
import tempfile

ROOT = Path(__file__).resolve().parent.parent
URL = 'http://127.0.0.1:8080/'
OUT = Path(tempfile.gettempdir()) / 'bitshop-demo-checks'
OUT.mkdir(exist_ok=True)
catalogue = ROOT / 'products.json'
original = catalogue.read_bytes()
uploaded = []

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
    context = browser.new_context(viewport={'width': 1440, 'height': 1080})
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    def data(): return json.loads(catalogue.read_text(encoding='utf-8'))
    def row(id): return page.locator(f'[data-product-id="{id}"]')
    def field(key): return page.locator(f'[name="new_product[{key}]"]')
    def ids(): return page.locator('[data-product-id]:visible').evaluate_all('(nodes) => nodes.map(n => n.dataset.productId)')
    def no_overflow(): assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Horizontal overflow'
    try:
        page.goto(URL + 'admin.php')
        page.get_by_label('Mot de passe', exact=True).fill('wrong-password')
        page.get_by_role('button', name='Se connecter', exact=True).click()
        expect(page.get_by_text('Wrong password.')).to_be_visible()
        page.get_by_label('Mot de passe', exact=True).fill('admin')
        page.get_by_role('button', name='Se connecter', exact=True).click()
        expect(page.locator('[data-product-id]')).to_have_count(6)
        assert ids() == ['7', '2', '3', '4', '5', '6']
        page.screenshot(path=str(OUT / 'admin-desktop.png'), full_page=True)
        no_overflow()
        page.get_by_role('searchbox', name='Rechercher un produit').fill('bronze')
        assert ids() == ['2']
        expect(row('2').locator('.drag-handle')).to_be_disabled()
        page.get_by_role('searchbox', name='Rechercher un produit').fill('nothing-matches')
        expect(page.locator('[data-empty-products]')).to_be_visible()
        page.get_by_role('searchbox', name='Rechercher un produit').fill('')
        page.get_by_label('Trier les produits').select_option('price-desc')
        assert ids() == ['6', '5', '4', '3', '2', '7']
        page.get_by_label('Trier les produits').select_option('order')
        row('7').get_by_role('switch').click()
        expect(row('7').get_by_role('switch')).to_have_attribute('aria-checked', 'false')
        assert data()['products']['7']['active'] is False
        shop = context.new_page()
        for route, selector in [('home.html', '[data-product-card]'), ('payment.html?value=7', '[data-value]')]:
            shop.goto(URL + route)
            expect(shop.locator(selector)).to_have_count(5)
        assert 'Starter Pack' not in shop.locator('#payTitle').inner_text()
        row('7').get_by_role('switch').click()
        expect(row('7').get_by_role('switch')).to_have_attribute('aria-checked', 'true')
        page.locator('[data-product-list]').scroll_into_view_if_needed()
        row('7').locator('.drag-handle').drag_to(row('3'), target_position={'x': 100, 'y': 100})
        expect(page.locator('[data-toast]')).to_have_text('Ordre d’affichage enregistré.')
        assert ids() == ['2', '3', '7', '4', '5', '6'], ids()
        assert data()['products']['7']['sort_order'] == 3
        page.reload()
        assert ids() == ['2', '3', '7', '4', '5', '6']
        row('7').locator('.drag-handle').press('ArrowUp')
        expect(row('7').locator('[data-order-number]').first).to_have_text('2')
        assert data()['products']['7']['sort_order'] == 2
        saved = catalogue.read_bytes()
        token = page.locator('[name="csrf_token"]').first.input_value()
        for form in [dict(action='toggle', id='7', active='0'), dict(action='reorder', csrf_token=token, ids='["7","7"]')]:
            response = context.request.post(URL + 'admin.php', form=form)
            assert response.status == 400
            assert catalogue.read_bytes() == saved
        page.route('**/admin.php', lambda route: route.fulfill(status=500, content_type='application/json', body='{"error":"Test erreur"}') if route.request.method == 'POST' else route.continue_())
        row('7').get_by_role('switch').click()
        expect(page.locator('[data-toast]')).to_have_text('Test erreur')
        expect(row('7').get_by_role('switch')).to_have_attribute('aria-checked', 'true')
        before_ids = ids()
        row('7').locator('.drag-handle').press('ArrowDown')
        expect(row('7').locator('.drag-handle')).to_be_enabled()
        assert ids() == before_ids
        page.unroute('**/admin.php')
        row('7').get_by_role('button', name='Éditer').click()
        field('name').fill('Starter Pack edited')
        field('payment_url').fill('https://example.com/checkout')
        page.get_by_role('button', name='Enregistrer le produit', exact=True).click()
        expect(page.get_by_text('Produit enregistré.', exact=True)).to_be_visible()
        assert [item['src'] for item in data()['products']['7']['media']] == [item['src'] for item in json.loads(original)['products']['7']['media']]
        assert data()['roulette'] == json.loads(original)['roulette']
        shop.goto(URL + 'home.html')
        expect(shop.locator('[data-product-card="7"]').get_by_role('link', name='Select')).to_have_attribute('href', 'https://example.com/checkout')
        saved = catalogue.read_bytes()
        response = context.request.post(URL + 'admin.php', form={'csrf_token': token, 'update_product': '7', 'new_product[name]': 'Bad', 'new_product[usd]': '5', 'new_product[sort_order]': '-1'})
        assert 'display order between 1 and 1000000' in response.text()
        assert catalogue.read_bytes() == saved
        field('name').fill('Temporary gallery test')
        field('usd').fill('12.50')
        page.locator('[data-target="new-product-media"]').click()
        page.locator('[data-library-search]').fill('media/packs/Pack')
        page.locator('[data-library-checkbox][value="media/packs/Pack1.png"]').check()
        page.locator('[data-library-checkbox][value="media/packs/Pack2.png"]').check()
        page.locator('[data-apply-library]').click()
        page.locator('#image-upload').set_input_files(str(ROOT / 'Pack1.png'))
        expect(page.locator('[data-toast]')).to_have_text('Image importée.')
        uploaded.extend([ROOT / src for src in field('media').input_value().splitlines() if src.startswith('media/uploads/')])
        page.get_by_role('button', name='Ajouter le produit', exact=True).click()
        expect(page.get_by_text('Produit ajouté.', exact=True)).to_be_visible()
        new_id = next(key for key in data()['products'] if key not in json.loads(original)['products'])
        assert len(data()['products'][new_id]['media']) == 3
        shop.goto(URL + 'home.html')
        card = shop.locator(f'[data-product-card="{new_id}"]')
        expect(card.locator('[data-carousel]')).to_have_attribute('data-multi', 'true')
        card.locator('[data-carousel-next]').click()
        expect(card.locator('.carousel-slide.is-active img')).to_have_attribute('src', 'media/packs/Pack2.png')
        page.on('dialog', lambda dialog: dialog.accept())
        row(new_id).get_by_role('button', name='Supprimer').click()
        expect(page.get_by_text('Produit supprimé.', exact=True)).to_be_visible()
        assert new_id not in data()['products']
        page.set_viewport_size({'width': 390, 'height': 844})
        no_overflow()
        page.screenshot(path=str(OUT / 'admin-mobile.png'), full_page=True)
        page.get_by_role('link', name='Payments', exact=True).click()
        expect(page.get_by_role('button', name='Enregistrer les paiements', exact=True)).to_be_visible()
        no_overflow()
        page.get_by_role('link', name='Roulette', exact=True).click()
        page.get_by_role('button', name='Enregistrer la roulette', exact=True).click()
        assert data()['roulette'] == json.loads(original)['roulette']
        no_overflow()
        page.get_by_role('link', name='Déconnexion', exact=True).click()
        expect(page.get_by_role('button', name='Se connecter', exact=True)).to_be_visible()
        unauthorized = context.request.post(URL + 'admin.php', form={'action': 'toggle', 'id': '7', 'active': '0'})
        assert unauthorized.status == 401
        assert not errors, errors
        print('PASS: desktop/mobile layout, search/sort, toggle/shop, drag/keyboard persistence, rollback, edit, validation/CSRF, library/upload/gallery, delete, payments/roulette, logout', flush=True)
    finally:
        catalogue.write_bytes(original)
        for path in uploaded:
            if path.is_relative_to(ROOT / 'media/uploads'): path.unlink(missing_ok=True)
        browser.close()
