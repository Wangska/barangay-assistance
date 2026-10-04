<?php

$user = current_user();

?>

<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title><?= e(APP_NAME) ?></title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="<?= BASE_URL ?>/assets/css/style.css?v=20261004n"
        rel="stylesheet"
    >

</head>

<body class="<?= trim((($user['role_name'] ?? '') === 'Senior Citizen' ? 'senior-interface' : '') . ' ' . ($extraBodyClass ?? '')) ?>" data-base-url="<?= e(BASE_URL) ?>">

<?php $flashItems = flashes(); ?>
<?php if ($flashItems): ?>

    <div class="toast-container position-fixed top-0 end-0 p-3">

        <?php foreach ($flashItems as $item): ?>

            <div
                class="toast show text-bg-<?= e($item['type']) ?> mb-2"
                role="alert"
            >

                <div class="toast-body">

                    <?= e($item['message']) ?>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

<?php endif; ?>
