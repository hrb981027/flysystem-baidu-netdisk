<?php

declare(strict_types=1);

namespace Hrb981027\FlysystemBaiduNetdisk;

use Hyperf\Filesystem\Contract\AdapterFactoryInterface;

class BaiduNetdiskAdapterFactory implements AdapterFactoryInterface
{
    public function make(array $options)
    {
        return new BaiduNetdiskAdapter($options);
    }
}