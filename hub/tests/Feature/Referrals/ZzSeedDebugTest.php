<?php
it('debug seed', function () {
    $this->useIngestInfrastructure();
    $code = \Illuminate\Support\Facades\Artisan::call('igr:seed-demo', ['--customers' => 60, '--orders' => 300, '--process' => true]);
    dump($code, mb_substr(\Illuminate\Support\Facades\Artisan::output(), -1500));
});
