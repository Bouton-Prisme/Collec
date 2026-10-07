<?php
session_start();
require_once __DIR__ . '/payment-settings.php';
require_once __DIR__ . '/orders.php';
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

    $paymentUrl = trim((string) ($product['payment_url'] ?? ''));
    if ($paymentUrl !== '' && (!filter_var($paymentUrl, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($paymentUrl, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true))) {
        throw new InvalidArgumentException('Le lien de paiement doit être une URL HTTP ou HTTPS valide.');
    }
    return [
        'active' => !isset($product['active']) || in_array($product['active'], [true, 1, '1'], true),
        'payment_url' => $paymentUrl,
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
$activeTab = in_array($_GET['tab'] ?? '', ['roulette', 'payment', 'orders'], true) ? $_GET['tab'] : 'products';

$isApi = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']);
if ($isApi && !$isAdmin) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Session expirée. Reconnectez-vous.']);
    exit;
}
try {
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        throw new InvalidArgumentException('Session du formulaire expirée. Rechargez la page puis réessayez.');
    }
}
if ($isApi) {
    $action = $_POST['action'];
    $next = $data;
    $result = ['ok' => true];
    if ($action === 'toggle') {
        $id = (string) ($_POST['id'] ?? '');
        if (!isset($next['products'][$id])) throw new InvalidArgumentException('Produit introuvable.');
        $next['products'][$id]['active'] = ($_POST['active'] ?? '') === '1';
    } elseif ($action === 'reorder') {
        $ids = json_decode($_POST['ids'] ?? '', true);
        $expected = array_map('strval', array_keys($next['products']));
        if (!is_array($ids) || array_filter($ids, function ($id) { return !is_string($id); }) || count($ids) !== count($expected) || count(array_unique($ids)) !== count($expected) || array_diff($ids, $expected)) {
            throw new InvalidArgumentException('La liste a changé. Rechargez la page avant de réordonner les produits.');
        }
        foreach ($ids as $position => $id) $next['products'][$id]['sort_order'] = $position + 1;
    } elseif ($action === 'upload') {
        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) throw new InvalidArgumentException('Choisissez une image de moins de 5 Mo.');
        $info = @getimagesize($file['tmp_name']);
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (!$extension) throw new InvalidArgumentException('Formats acceptés : PNG, JPG et WEBP.');
        $uploadDir = $mediaDir . '/uploads';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) throw new RuntimeException('Impossible de créer le dossier des images.');
        $src = 'media/uploads/' . bin2hex(random_bytes(16)) . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $src)) throw new RuntimeException('Impossible d’enregistrer l’image.');
        $result['src'] = $src;
    } else {
        throw new InvalidArgumentException('Action inconnue.');
    }
    if ($action !== 'upload' && !save_products($productsFile, $next)) throw new RuntimeException('Impossible d’enregistrer les produits.');
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['update_product'])) {
    $id = (string) $_POST['update_product'];
    if (!isset($data['products'][$id])) throw new InvalidArgumentException('Produit introuvable.');
    $updated = product_from_form($_POST['new_product'] ?? []);
    $data['products'][$id] = array_replace($data['products'][$id], $updated);
    if (!save_products($productsFile, $data)) throw new RuntimeException('Impossible d’enregistrer le produit.');
    $saved = true;
}
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_order'])) {
    order_admin_update($_POST);
    $saved = true;
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

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_roulette'])) {
    $data['roulette']['media'] = media_from_form($_POST['roulette']['media'] ?? '', 'Roulette');

    if (!save_products($productsFile, $data)) {
        $error = 'Could not save products.json. Check file permissions.';
    } else {
        $saved = true;
    }
}
} catch (InvalidArgumentException | RuntimeException $exception) {
    if ($isApi) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => $exception->getMessage()]);
        exit;
    }
    $error = $exception->getMessage();
}

// Redirect successful form submissions so refreshing cannot create a duplicate.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && ($saved || $added || $deleted || $paymentSaved)) {
    $_SESSION['admin_notice'] = $paymentSaved ? 'payment' : ($added ? 'added' : ($deleted ? 'deleted' : 'saved'));
    header('Location: admin.php?tab=' . $activeTab, true, 303);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['admin_notice'])) {
    $notice = $_SESSION['admin_notice'];
    unset($_SESSION['admin_notice']);
    $saved = $notice === 'saved';
    $added = $notice === 'added';
    $deleted = $notice === 'deleted';
    $paymentSaved = $notice === 'payment';
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
<?php require __DIR__ . '/admin-view.php'; ?>
