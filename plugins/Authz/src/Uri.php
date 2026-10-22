<?php

namespace Playbloom\Plugin\Authz;

/**
 * Maps a catalogue request path to the package it concerns.
 */
final class Uri
{
    /**
     * The package a path is for, or null for whole-catalogue documents
     * (/, /index.html, /packages.json, /include/*), which are gated on the
     * credential alone.
     *
     * Reads path segments, never the archive's file name: versions contain
     * dashes, and Satis also writes commit-reference names.
     */
    public static function package(string $uri): ?string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        $path = rawurldecode($path);

        // /p2/vendor/name.json and /p2/vendor/name~dev.json
        if (1 === preg_match('#^/p2/([^/]+)/([^/]+?)(~dev)?\.json$#', $path, $matches)) {
            return strtolower($matches[1] . '/' . $matches[2]);
        }
        // /dist/vendor/name/<anything>
        if (1 === preg_match('#^/dist/([^/]+)/([^/]+)/#', $path, $matches)) {
            return strtolower($matches[1] . '/' . $matches[2]);
        }

        return null;
    }
}
