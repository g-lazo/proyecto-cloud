<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$bucket = getenv('S3_BUCKET_ENSAYOS') ?: '';
$links  = [];
$source = 'local';

if ($bucket === '') {
    $dir = __DIR__ . '/uploads';
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.pdf') as $path) {
            $name = basename($path);
            $links[] = [
                'key'  => $name,
                'url'  => '/uploads/' . rawurlencode($name),
                'size' => filesize($path),
            ];
        }
    }
} else {
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
<div class="py-12">
    <header class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight">Repositorio de ensayos</h1>
        <p class="text-sm text-slate-500 mt-1">
            Documentos del semestre.
            <?php if ($source === 's3'): ?>
                Servidos desde AWS S3 con URLs firmadas (válidas 15 min).
            <?php else: ?>
                <span class="text-slate-400">(modo local · fase 1)</span>
            <?php endif; ?>
        </p>
    </header>

    <?php if (!$links): ?>
        <div class="text-center py-16">
            <p class="text-slate-400">No hay archivos disponibles.</p>
            <?php if ($source === 'local'): ?>
                <p class="text-xs text-slate-400 mt-3">
                    Coloca tus PDFs en <code class="px-1.5 py-0.5 bg-slate-100 rounded">public/uploads/</code> y refresca.
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <ul class="divide-y divide-slate-100">
        <?php foreach ($links as $l):
            $kb = $l['size'] > 0 ? number_format($l['size'] / 1024, 1) . ' KB' : '';
        ?>
            <li class="py-4 flex items-center justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0 flex-1">
                    <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center text-slate-500 text-xs font-semibold shrink-0">
                        PDF
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium truncate"><?= e((string)$l['key']) ?></div>
                        <div class="text-xs text-slate-400 mt-0.5"><?= e($kb) ?></div>
                    </div>
                </div>
                <a href="<?= e_attr((string)$l['url']) ?>" target="_blank" rel="noopener noreferrer"
                   class="text-sm text-slate-500 hover:text-slate-900 shrink-0">
                    Descargar →
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
