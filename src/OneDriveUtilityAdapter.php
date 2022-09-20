<?php

namespace Justus\FlysystemOneDrive;

abstract class OneDriveUtilityAdapter
{
    protected ?string $pathPrefix;
    protected string $pathSeparator = '/';

    public function setPathPrefix(string $prefix): void
    {
        if ($prefix === '') {
            $this->pathPrefix = null;

            return;
        }

        $this->pathPrefix = rtrim($prefix, '\\/') . $this->pathSeparator;
    }

    public function getPathPrefix(): ?string
    {
        return $this->pathPrefix;
    }

    public function applyPathPrefix(string $path): string
    {
        return $this->getPathPrefix() . ltrim($path, '\\/');
    }

    public function removePathPrefix(string $path): string
    {
        return substr($path, strlen($this->getPathPrefix()));
    }
}
