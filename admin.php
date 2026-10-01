<?php
session_start();
require_once __DIR__ . '/payment-settings.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$adminPassword = 'admin';
$productsFile = __DIR__ . DIRECTORY_SEPARATOR . 'products.json';
$mediaDir = __DIR__ . DIRECTORY_SEPARATOR . 'media';
$error = '';
$saved = false;
$added = false;
$deleted = false;
$paymentSaved = false;
try {
    $paymentSettings = load_payment_settings();
} catch (Throwable $exception) {
    $paymentSettings = payment_defaults();
    $error = 'La configuration de paiement est illisible. Réenregistrez les réglages pour la réparer.';
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function load_products($file) {
    $fallback = ['products' => [], 'roulette' => ['media' => []]];

    if (!file_exists($file)) {
        return $fallback;
    }

    $json = file_get_contents($file);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        return $fallback;
    }

    if (!isset($data['products']) || !is_array($data['products'])) {
        $data['products'] = [];
    }

    if (!isset($data['roulette']) || !is_array($data['roulette'])) {
        $data['roulette'] = ['media' => []];
    }

    if (!isset($data['roulette']['media']) || !is_array($data['roulette']['media'])) {
        $data['roulette']['media'] = [];
    }

    return $data;
}

function media_from_form($value, $altPrefix) {
    $mediaLines = preg_split('/\R/', (string) $value);
    $media = [];

    foreach ($mediaLines as $line) {
        $src = trim($line);
        if ($src === '') {
            continue;
        }

        $media[] = [
            'src' => $src,
            'alt' => $altPrefix . ' image'
        ];
    }

    return $media;
}

function media_src_list($mediaItems) {
    $media = [];

    foreach (($mediaItems ?? []) as $item) {
        $media[] = is_array($item) ? (string) ($item['src'] ?? '') : (string) $item;
    }

    return array_values(array_filter($media, function ($src) {
        return trim($src) !== '';
    }));
}

function product_from_form($product) {
    $name = trim((string) ($product['name'] ?? ''));
    $order = filter_var($product['sort_order'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
    $price = filter_var($product['usd'] ?? 0, FILTER_VALIDATE_FLOAT);
    if ($name === '' || $price === false || $price < 0 || $order === false) {
        throw new InvalidArgumentException('Enter a product name, a non-negative price and a display order between 1 and 1000000.');
    }

    return [
        'name' => $name,
        'usd' => (float) $price,
        'sort_order' => $order,
        'badge' => trim((string) ($product['badge'] ?? '')),
        'description' => trim((string) ($product['description'] ?? '')),
        'tagline' => trim((string) ($product['tagline'] ?? '')),
        'media' => media_from_form($product['media'] ?? '', ($name !== '' ? $name : 'Product'))
    ];
}

function normalize_media_path($path) {
    return str_replace('\\', '/', $path);
}

function scan_media_library($dir) {
    if (!is_dir($dir)) {
        return [];
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $extension = strtolower($file->getExtension());
        if (!in_array($extension, $allowed, true)) {
            continue;
        }

        $relative = normalize_media_path(substr($file->getPathname(), strlen($dir) + 1));
        $src = 'media/' . $relative;
        $files[] = [
            'src' => $src,
            'name' => $file->getBasename(),
            'folder' => normalize_media_path(dirname($relative)) === '.' ? 'media' : 'media/' . normalize_media_path(dirname($relative))
        ];
    }

    usort($files, function ($a, $b) {
        return strnatcasecmp($a['src'], $b['src']);
    });

    return $files;
}

function next_product_id($products) {
    $max = 0;

    foreach (array_keys($products) as $id) {
        if (ctype_digit((string) $id)) {
            $max = max($max, (int) $id);
        }
    }

    return (string) ($max + 1);
}

function save_products($file, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return $json !== false && file_put_contents($file, $json . PHP_EOL, LOCK_EX) !== false;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals($adminPassword, (string) $_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    }

    $error = 'Wrong password.';
}

$isAdmin = !empty($_SESSION['admin']);
$data = load_products($productsFile);
$mediaLibrary = $isAdmin ? scan_media_library($mediaDir) : [];
$activeTab = in_array($_GET['tab'] ?? '', ['roulette', 'payment'], true) ? $_GET['tab'] : 'products';

try {
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        throw new InvalidArgumentException('Session du formulaire expirée. Rechargez la page puis réessayez.');
    }
}
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    $nextSettings = validate_payment_settings($_POST['payment'] ?? []);
    save_payment_settings($nextSettings);
    $paymentSettings = $nextSettings;
    $paymentSaved = true;
    $error = '';
}
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $newProduct = product_from_form($_POST['new_product'] ?? []);

    if ($newProduct['name'] === '') {
        $error = 'Product name is required.';
    } else {
        $newId = next_product_id($data['products']);
        $data['products'][$newId] = $newProduct;
        ksort($data['products'], SORT_NATURAL);

        if (save_products($productsFile, $data)) {
            $added = true;
        } else {
            $error = 'Could not save products.json. Check file permissions.';
        }
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product'])) {
    $deleteId = preg_replace('/[^0-9]/', '', (string) $_POST['delete_product']);

    if ($deleteId === '' || !isset($data['products'][$deleteId])) {
        $error = 'Product not found.';
    } else {
        unset($data['products'][$deleteId]);
        ksort($data['products'], SORT_NATURAL);

        if (save_products($productsFile, $data)) {
            $deleted = true;
        } else {
            $error = 'Could not save products.json. Check file permissions.';
        }
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['products']) && !isset($_POST['delete_product'])) {
    $next = $data;
    $next['products'] = [];

    foreach ($_POST['products'] as $id => $product) {
        $id = preg_replace('/[^0-9]/', '', (string) $id);
        if ($id === '') {
            continue;
        }

        $next['products'][$id] = product_from_form($product);
    }

    ksort($next['products'], SORT_NATURAL);

    if (!save_products($productsFile, $next)) {
        $error = 'Could not save products.json. Check file permissions.';
    } else {
        $saved = true;
        $data = $next;
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_roulette'])) {
    $data['roulette']['media'] = media_from_form($_POST['roulette']['media'] ?? '', 'Roulette');

    if (!save_products($productsFile, $data)) {
        $error = 'Could not save products.json. Check file permissions.';
    } else {
        $saved = true;
    }
}
} catch (InvalidArgumentException | RuntimeException $exception) {
    $error = $exception->getMessage();
}

// Display order is independent of product IDs (which are used by carts and URLs).
uksort($data['products'], function ($a, $b) use ($data) {
    $orderA = (int) ($data['products'][$a]['sort_order'] ?? $a);
    $orderB = (int) ($data['products'][$b]['sort_order'] ?? $b);
    return ($orderA <=> $orderB) ?: strnatcmp((string) $a, (string) $b);
});
$nextOrder = 1;
foreach ($data['products'] as $id => $product) {
    $nextOrder = max($nextOrder, (int) ($product['sort_order'] ?? $id) + 1);
}
$nextOrder = min(1000000, $nextOrder);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Administration — BitShop</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { darkMode: 'class' };</script>
</head>
<body class="min-h-screen bg-[#0b0c10] text-white">
  <main class="mx-auto max-w-6xl px-4 py-8">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold">Administration BitShop</h1>
        <p class="mt-1 text-sm text-gray-400">Produits, médiathèque et paramètres de paiement.</p>
      </div>
      <?php if ($isAdmin): ?>
        <div class="flex items-center gap-3">
          <a href="home.html" class="rounded border border-white/20 px-3 py-2 text-sm hover:bg-white hover:text-black">Home</a>
          <a href="admin.php?logout=1" class="rounded border border-white/20 px-3 py-2 text-sm hover:bg-white hover:text-black">Logout</a>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($error): ?>
      <div class="mb-6 rounded border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-200"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($saved): ?>
      <div class="mb-6 rounded border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm text-emerald-200">Products saved.</div>
    <?php endif; ?>

    <?php if ($paymentSaved): ?>
      <div role="status" class="mb-6 rounded border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm text-emerald-200">Configuration de paiement enregistrée.</div>
    <?php endif; ?>

    <?php if ($added): ?>
      <div class="mb-6 rounded border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm text-emerald-200">Product added.</div>
    <?php endif; ?>

    <?php if ($deleted): ?>
      <div class="mb-6 rounded border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm text-emerald-200">Product deleted.</div>
    <?php endif; ?>

    <?php if (!$isAdmin): ?>
      <form method="post" class="max-w-sm rounded border border-white/10 bg-white/5 p-5">
        <label class="block text-sm font-semibold" for="password">Password</label>
        <input id="password" name="password" type="password" class="mt-2 w-full rounded border border-white/10 bg-black px-3 py-2 text-white" required>
        <button class="mt-4 rounded bg-cyan-950 px-4 py-2 font-bold text-white hover:opacity-90">Login</button>
      </form>
    <?php else: ?>
      <nav class="mb-6 flex gap-2 border-b border-white/10">
        <a href="admin.php?tab=products" class="border-b-2 px-4 py-3 text-sm font-semibold <?= $activeTab === 'products' ? 'border-cyan-300 text-white' : 'border-transparent text-gray-400 hover:text-white' ?>">Products</a>
        <a href="admin.php?tab=roulette" class="border-b-2 px-4 py-3 text-sm font-semibold <?= $activeTab === 'roulette' ? 'border-cyan-300 text-white' : 'border-transparent text-gray-400 hover:text-white' ?>">Roulette</a>
        <a href="admin.php?tab=payment" class="border-b-2 px-4 py-3 text-sm font-semibold <?= $activeTab === 'payment' ? 'border-cyan-300 text-white' : 'border-transparent text-gray-400 hover:text-white' ?>">Paiements</a>
      </nav>

      <?php if ($activeTab === 'products'): ?>
      <section class="mb-8 rounded border border-indigo-500/10 bg-indigo-500 p-3">
        <h2 class="mb-4 text-lg font-semibold">Add product</h2>
        <form method="post" class="grid gap-4 md:grid-cols-2">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="add_product" value="1">
          <label class="block text-sm">
            <span class="font-semibold">Name</span>
            <input name="new_product[name]" class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white" required>
          </label>
          <label class="block text-sm">
            <span class="font-semibold">USD price</span>
            <input name="new_product[usd]" type="number" step="0.01" value="0" class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white">
          </label>
          <label class="block text-sm">
            <span class="font-semibold">Ordre d'affichage</span>
            <input name="new_product[sort_order]" type="number" min="1" max="1000000" step="1" value="<?= h($nextOrder) ?>" required class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white">
            <span class="mt-1 block text-xs text-gray-300">Le plus petit nombre s'affiche en premier. En cas d'égalité, l'identifiant départage les produits.</span>
          </label>
          <label class="block text-sm">
            <span class="font-semibold">Badge</span>
            <input name="new_product[badge]" class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white">
          </label>
          <label class="block text-sm">
            <span class="font-semibold">Payment tagline</span>
            <input name="new_product[tagline]" class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white">
          </label>
          <label class="block text-sm md:col-span-2">
            <span class="font-semibold">Home description</span>
            <textarea name="new_product[description]" rows="3" class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white"></textarea>
          </label>
          <div class="block text-sm md:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span class="font-semibold">Images</span>
              <button type="button" class="rounded border border-white/20 px-3 py-2 text-sm hover:bg-white hover:text-black" data-open-library data-target="new-product-media">Open library</button>
            </div>
            <textarea id="new-product-media" name="new_product[media]" rows="3" class="sr-only" data-media-field></textarea>
            <div class="mt-2 min-h-24 rounded border border-white/10 bg-black/50 p-3" data-media-preview data-empty="No selected images."></div>
          </div>
          <div class="md:col-span-2">
            <button class="rounded bg-cyan-950 px-5 py-3 font-bold text-white hover:opacity-90">Add product</button>
          </div>
        </form>
      </section>

      <form method="post" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <?php foreach ($data['products'] as $id => $product): ?>
          <?php
            $media = [];
            foreach (($product['media'] ?? []) as $item) {
                $media[] = is_array($item) ? (string) ($item['src'] ?? '') : (string) $item;
            }
          ?>
          <section class="rounded border border-white/10 bg-white/5 p-5">
            <div class="mb-4 flex items-center justify-between gap-4">
              <h2 class="text-lg font-semibold">Product <?= h($id) ?></h2>
              <button
                name="delete_product"
                value="<?= h($id) ?>"
                class="rounded border border-red-500/50 px-3 py-2 text-sm text-red-200 hover:bg-red-500 hover:text-white"
                onclick="return confirm('Delete product <?= h($id) ?>?');">
                Delete
              </button>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
              <label class="block text-sm">
                <span class="font-semibold">Name</span>
                <input name="products[<?= h($id) ?>][name]" value="<?= h($product['name'] ?? '') ?>" class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white">
              </label>
              <label class="block text-sm">
                <span class="font-semibold">USD price</span>
                <input name="products[<?= h($id) ?>][usd]" type="number" step="0.01" value="<?= h($product['usd'] ?? 0) ?>" class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white">
              </label>
              <label class="block text-sm">
                <span class="font-semibold">Ordre d'affichage</span>
                <input name="products[<?= h($id) ?>][sort_order]" type="number" min="1" max="1000000" step="1" value="<?= h($product['sort_order'] ?? $id) ?>" required class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white">
                <span class="mt-1 block text-xs text-gray-400">Le plus petit nombre s'affiche en premier sur l'accueil et le paiement.</span>
              </label>
              <label class="block text-sm">
                <span class="font-semibold">Badge</span>
                <input name="products[<?= h($id) ?>][badge]" value="<?= h($product['badge'] ?? '') ?>" class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white">
              </label>
              <label class="block text-sm">
                <span class="font-semibold">Payment tagline</span>
                <input name="products[<?= h($id) ?>][tagline]" value="<?= h($product['tagline'] ?? '') ?>" class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white">
              </label>
              <label class="block text-sm md:col-span-2">
                <span class="font-semibold">Home description</span>
                <textarea name="products[<?= h($id) ?>][description]" rows="3" class="mt-1 w-full rounded border border-white/10 bg-black px-3 py-2 text-white"><?= h($product['description'] ?? '') ?></textarea>
              </label>
              <div class="block text-sm md:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-3">
                  <span class="font-semibold">Images</span>
                  <button type="button" class="rounded border border-white/20 px-3 py-2 text-sm hover:bg-white hover:text-black" data-open-library data-target="product-<?= h($id) ?>-media">Open library</button>
                </div>
                <textarea id="product-<?= h($id) ?>-media" name="products[<?= h($id) ?>][media]" rows="3" class="sr-only" data-media-field><?= h(implode("\n", $media)) ?></textarea>
                <div class="mt-2 min-h-24 rounded border border-white/10 bg-black/50 p-3" data-media-preview data-empty="No selected images."></div>
              </div>
            </div>
          </section>
        <?php endforeach; ?>

        <div class="sticky bottom-0 border-t border-white/10 bg-[#0b0c10]/95 py-4 backdrop-blur">
          <button class="rounded bg-cyan-950 px-5 py-3 font-bold text-white hover:opacity-90">Save products</button>
          <a href="home.html" class="ml-3 text-sm text-gray-300 hover:text-white">View home</a>
        </div>
      </form>
      <?php elseif ($activeTab === 'payment'): ?>
        <?php require __DIR__ . '/admin-payment.php'; ?>
      <?php else: ?>
        <?php $rouletteMedia = media_src_list($data['roulette']['media'] ?? []); ?>
        <form method="post" class="space-y-5">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="save_roulette" value="1">
          <section class="rounded border border-white/10 bg-white/5 p-5">
            <div class="mb-4 flex items-center justify-between gap-4">
              <div>
                <h2 class="text-lg font-semibold">Roulette media</h2>
                <p class="mt-1 text-sm text-gray-400">Images used by roulette.html for random selection.</p>
              </div>
              <a href="roulette.html" class="rounded border border-white/20 px-3 py-2 text-sm hover:bg-white hover:text-black">View roulette</a>
            </div>
            <div class="block text-sm">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="font-semibold">Images</span>
                <button type="button" class="rounded border border-white/20 px-3 py-2 text-sm hover:bg-white hover:text-black" data-open-library data-target="roulette-media">Open library</button>
              </div>
              <textarea id="roulette-media" name="roulette[media]" rows="3" class="sr-only" data-media-field><?= h(implode("\n", $rouletteMedia)) ?></textarea>
              <div class="mt-2 min-h-24 rounded border border-white/10 bg-black/50 p-3" data-media-preview data-empty="No selected images."></div>
            </div>
          </section>

          <div class="sticky bottom-0 border-t border-white/10 bg-[#0b0c10]/95 py-4 backdrop-blur">
            <button class="rounded bg-cyan-950 px-5 py-3 font-bold text-white hover:opacity-90">Save roulette</button>
          </div>
        </form>
      <?php endif; ?>

      <div class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 p-4" data-library-modal aria-hidden="true">
        <div class="flex max-h-[90vh] w-full max-w-5xl flex-col rounded border border-white/10 bg-[#101218] shadow-2xl">
          <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 p-4">
            <div>
              <h2 class="text-lg font-semibold">Media library</h2>
              <p class="mt-1 text-sm text-gray-400">Drop image folders in <code class="rounded bg-black px-1 py-0.5">media/</code>, then select the images for this product.</p>
            </div>
            <button type="button" class="rounded border border-white/20 px-3 py-2 text-sm hover:bg-white hover:text-black" data-close-library>Close</button>
          </div>

          <div class="flex flex-wrap items-center gap-3 border-b border-white/10 p-4">
            <input type="search" placeholder="Search images" class="min-w-56 flex-1 rounded border border-white/10 bg-black px-3 py-2 text-sm text-white" data-library-search>
            <button type="button" class="rounded border border-white/20 px-3 py-2 text-sm hover:bg-white hover:text-black" data-library-select-all>Select visible</button>
            <button type="button" class="rounded border border-white/20 px-3 py-2 text-sm hover:bg-white hover:text-black" data-library-clear>Clear</button>
            <span class="text-sm text-gray-400" data-library-count></span>
          </div>

          <div class="min-h-72 overflow-y-auto p-4">
            <?php if (empty($mediaLibrary)): ?>
              <div class="rounded border border-dashed border-white/20 p-8 text-center text-sm text-gray-300">
                No images found. Create folders inside <code class="rounded bg-black px-1 py-0.5">media/</code> and add PNG, JPG, WEBP, GIF, AVIF or SVG files.
              </div>
            <?php else: ?>
              <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5" data-library-grid>
                <?php foreach ($mediaLibrary as $image): ?>
                  <label class="group cursor-pointer rounded border border-white/10 bg-black/40 p-2 hover:border-cyan-300" data-library-item data-src="<?= h($image['src']) ?>">
                    <div class="relative aspect-square overflow-hidden rounded bg-white/5">
                      <img src="<?= h($image['src']) ?>" alt="<?= h($image['name']) ?>" loading="lazy" class="h-full w-full object-cover">
                      <input type="checkbox" value="<?= h($image['src']) ?>" class="absolute left-2 top-2 h-5 w-5 accent-cyan-400" data-library-checkbox>
                    </div>
                    <div class="mt-2 truncate text-xs font-semibold text-white" title="<?= h($image['name']) ?>"><?= h($image['name']) ?></div>
                    <div class="truncate text-xs text-gray-400" title="<?= h($image['folder']) ?>"><?= h($image['folder']) ?></div>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-3 border-t border-white/10 p-4">
            <span class="text-sm text-gray-400">Checked images are selected for import.</span>
            <button type="button" class="rounded bg-cyan-950 px-5 py-3 font-bold text-white hover:opacity-90" data-apply-library>Use selected images</button>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </main>
  <?php if ($isAdmin): ?>
    <script>
      const mediaLibrary = <?= json_encode($mediaLibrary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

      (() => {
        const modal = document.querySelector('[data-library-modal]');
        const search = document.querySelector('[data-library-search]');
        const count = document.querySelector('[data-library-count]');
        const items = Array.from(document.querySelectorAll('[data-library-item]'));
        const checkboxes = Array.from(document.querySelectorAll('[data-library-checkbox]'));
        let activeField = null;

        const selectedFromField = (field) => field.value
          .split(/\r?\n/)
          .map((value) => value.trim())
          .filter(Boolean);

        const writeField = (field, values) => {
          field.value = values.join('\n');
          renderPreview(field);
        };

        const renderPreview = (field) => {
          const preview = field.parentElement.querySelector('[data-media-preview]');
          const values = selectedFromField(field);

          if (!preview) return;
          if (!values.length) {
            preview.innerHTML = `<p class="text-sm text-gray-400">${preview.dataset.empty}</p>`;
            return;
          }

          preview.innerHTML = `
            <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
              ${values.map((src) => `
                <figure class="overflow-hidden rounded border border-white/10 bg-black">
                  <div class="relative aspect-square bg-white/5">
                    <img src="${escapeAttribute(src)}" alt="" loading="lazy" class="h-full w-full object-cover">
                    <button type="button" class="absolute right-1 top-1 grid h-7 w-7 place-items-center rounded-full bg-black/80 text-sm font-bold text-white hover:bg-red-600" data-remove-media="${escapeAttribute(src)}" aria-label="Remove ${escapeAttribute(src)}">x</button>
                  </div>
                  <figcaption class="truncate px-2 py-1 text-xs text-gray-300" title="${escapeAttribute(src)}">${escapeHtml(src.split('/').pop() || src)}</figcaption>
                </figure>
              `).join('')}
            </div>
          `;
        };

        const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#039;'
        }[char]));

        const escapeAttribute = escapeHtml;

        const updateCount = () => {
          if (!count) return;
          const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
          count.textContent = `${selected} selected`;
        };

        const openLibrary = (field) => {
          activeField = field;
          const selected = new Set(selectedFromField(field));
          checkboxes.forEach((checkbox) => {
            checkbox.checked = selected.has(checkbox.value);
          });
          if (search) search.value = '';
          filterItems('');
          updateCount();
          modal.classList.remove('hidden');
          modal.classList.add('flex');
          modal.setAttribute('aria-hidden', 'false');
        };

        const closeLibrary = () => {
          modal.classList.add('hidden');
          modal.classList.remove('flex');
          modal.setAttribute('aria-hidden', 'true');
          activeField = null;
        };

        const filterItems = (query) => {
          const term = query.trim().toLowerCase();
          items.forEach((item) => {
            item.classList.toggle('hidden', term !== '' && !item.dataset.src.toLowerCase().includes(term));
          });
        };

        document.querySelectorAll('[data-media-field]').forEach(renderPreview);

        document.querySelectorAll('[data-media-preview]').forEach((preview) => {
          preview.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-remove-media]');
            if (!removeButton) return;

            const field = preview.parentElement.querySelector('[data-media-field]');
            if (!field) return;

            writeField(field, selectedFromField(field).filter((src) => src !== removeButton.dataset.removeMedia));
          });
        });

        document.querySelectorAll('[data-open-library]').forEach((button) => {
          button.addEventListener('click', () => {
            const field = document.getElementById(button.dataset.target);
            if (field) openLibrary(field);
          });
        });

        document.querySelector('[data-close-library]')?.addEventListener('click', closeLibrary);
        modal?.addEventListener('click', (event) => {
          if (event.target === modal) closeLibrary();
        });

        search?.addEventListener('input', () => filterItems(search.value));
        checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateCount));

        document.querySelector('[data-library-select-all]')?.addEventListener('click', () => {
          items.filter((item) => !item.classList.contains('hidden')).forEach((item) => {
            const checkbox = item.querySelector('[data-library-checkbox]');
            if (checkbox) checkbox.checked = true;
          });
          updateCount();
        });

        document.querySelector('[data-library-clear]')?.addEventListener('click', () => {
          checkboxes.forEach((checkbox) => {
            checkbox.checked = false;
          });
          updateCount();
        });

        document.querySelector('[data-apply-library]')?.addEventListener('click', () => {
          if (!activeField) return;
          writeField(activeField, checkboxes.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value));
          closeLibrary();
        });

        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
            closeLibrary();
          }
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
