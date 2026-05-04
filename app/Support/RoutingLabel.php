<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Environment;

/**
 * Mints opaque, URL-safe routing identifiers for environments.
 *
 * Format: `<adjective>-<animal>-<3-char-base32>`, e.g. `wise-otter-x4f`.
 * The first two segments are picked from short curated lists so the label
 * stays human-readable; the suffix expands the namespace by ~32k slots
 * per (adjective, animal) collision class. Total: ~5 trillion labels.
 *
 * The label is the FAPI subdomain in subdomain mode and the URL path
 * prefix in path mode. It is NOT the operator-facing slug — that's
 * `Environment.slug` and lives on a per-project basis.
 */
final class RoutingLabel
{
    public static function generate(): string
    {
        for ($i = 0; $i < 16; $i++) {
            $label = self::randomLabel();
            if (! Environment::query()->withoutGlobalScopes()->where('routing_label', $label)->exists()) {
                return $label;
            }
        }

        // Vanishingly unlikely with our namespace size, but cap the loop
        // so a misconfigured table can't spin forever.
        throw new \RuntimeException('Could not allocate a unique routing label after 16 attempts.');
    }

    private static function randomLabel(): string
    {
        $adj = self::ADJECTIVES[random_int(0, count(self::ADJECTIVES) - 1)];
        $animal = self::ANIMALS[random_int(0, count(self::ANIMALS) - 1)];
        // Lower-case base32 alphabet (excludes 0, 1, l, o to avoid confusion).
        $alphabet = '23456789abcdefghijkmnpqrstuvwxyz';
        $suffix = '';
        for ($i = 0; $i < 3; $i++) {
            $suffix .= $alphabet[random_int(0, 31)];
        }

        return $adj.'-'.$animal.'-'.$suffix;
    }

    /** @var list<string> */
    private const ADJECTIVES = [
        'amber', 'azure', 'brave', 'breezy', 'bright', 'brisk', 'calm', 'candid',
        'clever', 'cosmic', 'crisp', 'curious', 'daring', 'deft', 'dusky', 'eager',
        'eerie', 'electric', 'fancy', 'feisty', 'fierce', 'fluffy', 'frosty', 'gentle',
        'giddy', 'glassy', 'gleaming', 'golden', 'graceful', 'grand', 'happy', 'hardy',
        'hidden', 'humble', 'icy', 'ivory', 'jolly', 'jovial', 'keen', 'kind',
        'lively', 'lofty', 'lone', 'loyal', 'lucky', 'lush', 'merry', 'mild',
        'misty', 'modern', 'mossy', 'muted', 'navy', 'neat', 'noble', 'nimble',
        'olive', 'opal', 'pale', 'patient', 'peaceful', 'plucky', 'plush', 'polar',
        'prim', 'proud', 'quaint', 'quick', 'quiet', 'rapid', 'rare', 'red',
        'regal', 'royal', 'rosy', 'rugged', 'sage', 'sandy', 'savvy', 'scarlet',
        'sharp', 'shiny', 'silent', 'silver', 'sleek', 'slick', 'small', 'smart',
        'smooth', 'snowy', 'soft', 'solar', 'solid', 'sonic', 'spry', 'stately',
        'steady', 'stout', 'sturdy', 'sunny', 'super', 'sweet', 'swift', 'tan',
        'teal', 'tender', 'tidy', 'tough', 'tranquil', 'trusty', 'twilight', 'urban',
        'valiant', 'vivid', 'warm', 'wary', 'wild', 'wise', 'witty', 'woven',
        'yellow', 'young', 'zealous',
    ];

    /** @var list<string> */
    private const ANIMALS = [
        'antelope', 'badger', 'bear', 'beaver', 'bison', 'butterfly', 'camel', 'cardinal',
        'caribou', 'cheetah', 'chinchilla', 'condor', 'cougar', 'crane', 'crow', 'deer',
        'dingo', 'dolphin', 'donkey', 'dove', 'dragonfly', 'duck', 'eagle', 'eel',
        'elephant', 'elk', 'falcon', 'ferret', 'finch', 'firefly', 'flamingo', 'fox',
        'frog', 'gazelle', 'gecko', 'gibbon', 'giraffe', 'goat', 'goose', 'gorilla',
        'grouse', 'hare', 'hawk', 'hedgehog', 'heron', 'hippo', 'hornet', 'horse',
        'iguana', 'impala', 'jackal', 'jaguar', 'kangaroo', 'koala', 'lemur', 'leopard',
        'lion', 'llama', 'lobster', 'lynx', 'magpie', 'mantis', 'marmot', 'meerkat',
        'mole', 'monkey', 'moose', 'moth', 'mouse', 'narwhal', 'newt', 'ocelot',
        'octopus', 'opossum', 'orca', 'otter', 'owl', 'panda', 'panther', 'parrot',
        'partridge', 'peacock', 'penguin', 'pheasant', 'pigeon', 'platypus', 'porcupine',
        'puffin', 'puma', 'rabbit', 'raccoon', 'ram', 'raven', 'reindeer', 'rhino',
        'robin', 'salamander', 'salmon', 'seahorse', 'seal', 'shark', 'sheep', 'skunk',
        'sloth', 'snail', 'snake', 'sparrow', 'spider', 'squid', 'squirrel', 'stag',
        'starfish', 'stingray', 'stork', 'swan', 'tapir', 'tiger', 'toad', 'tortoise',
        'toucan', 'turtle', 'viper', 'walrus', 'wasp', 'weasel', 'whale', 'wolf',
        'wolverine', 'wombat', 'yak', 'zebra',
    ];
}
