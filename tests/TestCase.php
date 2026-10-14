<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    /**
     * Waktu uji dibekukan agar tanggal tetap (mis. 2026-10-06) selalu berada pada
     * periode yang masih terbuka (cut-off periode Oktober = 10 November).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 10:00:00');
    }
}
