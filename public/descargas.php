<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$bucket = getenv('S3_BUCKET_ENSAYOS') ?: '';
$links  = [];
$source = 'local';

if ($bucket === '') {
    // ---- FASE 1: lista PDFs en public/uploads/ ----
    $dir = __DIR__ . '/uploads';
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.pdf') as $path) {
            $name = basename($path);
            $links[] = [
                'key'   => $name,
                'url'   => '/uploads/' . rawurlencode($name),
                'size'  => filesize($path),
            ];
        }
    }
} else {
    // ---- FASE 2: S3 + presigned URLs ----
    require __DIR__ . '/../vendor/autoload.php';
    $source = 's3';
    $region = getenv('AWS_REGION') ?: 'us-east-1';
    $s3 = new Aws\S3\S3Client(['version' => 'latest', 'region' => $region]);

    $result  = $s3->listObjectsV2(['Bucket' => $bucket]);
    $objects = $result['Contents'] ?? [];

    foreach ($objects as $obj) {
        $cmd = $s3->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key'    => $obj['Key'],
        ]);
        $req = $s3->createPresignedRequest($cmd, '+15 minutes');
        $links[] = [
            'key'  => $obj['Key'],
            'url'  => (string)$req->getUri(),
            'size' => (int)($obj['Size'] ?? 0),
        ];
    }
}

$pageTitle = 'Descargas';
require __DIR__ . '/../templates/header.php';
?>
<div class="bg-white rounded-xl shadow border border-slate-200 p-6">
    <h1 class="text-xl font-bold mb-1">Repositorio de ensayos</h1>
    <p class="text-sm text-slate-500 mb-4">
        Documentos del semestre.
        <?php if ($source === 's3'): ?>
            Servidos desde AWS S3 con URLs firmadas (válidas 15 min).
        <?php else: ?>
            <em>(Fase 1: servidos desde directorio local <code>public/uploads/</code>)</em>
        <?php endif; ?>
    </p>

    <?php if (!$links): ?>
        <p class="text-slate-500">No hay archivos disponibles.</p>
        <?php if ($source === 'local'): ?>
            <p class="text-xs text-slate-400 mt-2">
                Coloca tus PDFs en <code>public/uploads/</code> y refresca esta página.
            </p>
        <?php endif; ?>
    <?php else: ?>
        <ul class="divide-y divide-slate-100">
        <?php foreach ($links as $l):
            $kb = $l['size'] > 0 ? number_format($l['size'] / 1024, 1) . ' KB' : '';
        ?>
            <li class="py-2 flex items-center justify-between">
                <span>📄 <?= e((string)$l['key']) ?> <span class="text-slate-400 text-xs"><?= e($kb) ?></span></span>
                <a href="<?= e_attr((string)$l['url']) ?>" target="_blank" rel="noopener noreferrer"
                   class="text-indigo-600 hover:underline text-sm">Descargar →</a>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
