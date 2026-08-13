<?php

use App\Services\DeletedFileReconciler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('files:reconcile-deleted {--limit=100}', function () {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 1000],
    ]);

    if ($limit === false) {
        $this->error('The --limit option must be an integer between 1 and 1000.');

        return 2;
    }

    $summary = app(DeletedFileReconciler::class)->reconcile($limit);

    $this->info(sprintf(
        'Processed %d deleted file(s); cleaned %d; remaining %d.',
        $summary['processed'],
        $summary['cleaned'],
        $summary['remaining'],
    ));

    return $summary['remaining'] === 0 ? 0 : 1;
})->purpose('Retry private-object cleanup for DELETED file tombstones');
