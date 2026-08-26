<?php

namespace typedef\contentreview\web\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ContentReviewAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'content-review.css',
        ];

        $this->js = [
            'content-review.js',
        ];

        parent::init();
    }
}
