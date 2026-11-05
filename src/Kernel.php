<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        $qrDir = $this->getProjectDir() . '/public/google_reviews/qr_codes';
        if (!is_dir($qrDir)) {
            @mkdir($qrDir, 0775, true);
        }
        parent::boot();
    }
}
