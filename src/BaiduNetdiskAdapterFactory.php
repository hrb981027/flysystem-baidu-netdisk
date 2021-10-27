<?php

declare(strict_types=1);

namespace Hrb981027\FlysystemBaiduNetdisk;

use Hyperf\Filesystem\Contract\AdapterFactoryInterface;

class BaiduNetdiskAdapterFactory implements AdapterFactoryInterface
{
    public function make(array $options)
    {
        return make(BaiduNetdiskAdapter::class, [
            'config' => $options
        ]);
    }
}