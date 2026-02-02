<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

use function array_filter;
use function array_merge;
use function basename;
use function explode;
use function is_array;
use function is_string;
use function is_file;
use function is_readable;
use function ltrim;
use function realpath;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

#[Route('/meili')]
final class MeiliFileProxyController extends AbstractController
{
    public function __construct(
        #[Autowire('%survos_meili.file_proxy%')]
        private readonly array $config = [],
    ) {}

    #[Route('/file', name: 'meili_file_proxy', methods: ['GET'], options: ['expose' => true])]
    public function proxyFile(Request $request): Response
    {
        $config = array_merge([
            'enabled' => true,
            'allow_hidden' => false,
            'cache_control' => 'private, max-age=60',
            'roots' => [],
        ], $this->config);

        if (!$config['enabled']) {
            return $this->jsonError('file_proxy_disabled', Response::HTTP_FORBIDDEN);
        }

        $path = (string) $request->query->get('path', '');
        if ($path === '') {
            return $this->jsonError('missing_path', Response::HTTP_BAD_REQUEST);
        }

        $path = str_replace('\\', '/', $path);
        if (str_contains($path, "\0")) {
            return $this->jsonError('invalid_path', Response::HTTP_BAD_REQUEST);
        }

        $roots = $this->normalizeRoots($config['roots']);
        if ($roots === []) {
            return $this->jsonError(
                'file_proxy_no_roots',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['message' => 'Configure survos_meili.file_proxy.roots in your app config.']
            );
        }

        $resolved = $this->resolvePath($path, $roots, (bool) $config['allow_hidden']);
        if (isset($resolved['error'])) {
            return $this->jsonError(
                (string) $resolved['error'],
                Response::HTTP_NOT_FOUND,
                $resolved['details'] ?? null
            );
        }

        $response = new BinaryFileResponse($resolved['realpath']);
        $response->setAutoEtag();
        $response->setAutoLastModified();
        $response->headers->set('Cache-Control', (string) $config['cache_control']);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            basename($resolved['realpath'])
        );

        return $response;
    }

    /** @param array<string,string>|array<int,string>|mixed $roots */
    private function normalizeRoots(mixed $roots): array
    {
        if (!is_array($roots)) {
            return [];
        }

        $clean = [];
        foreach ($roots as $key => $root) {
            if (!is_string($root) || $root === '') {
                continue;
            }
            $root = rtrim($root, '/');

            if ($root === '') {
                continue;
            }

            $id = is_string($key) && $key !== '' ? $key : basename($root);
            $clean[$id] = $root;
        }

        return array_filter($clean, static fn(string $r) => $r !== '');
    }

    /**
     * @param array<string,string> $roots
     * @return array{realpath:string, relative:string}|array{error:string, details:array<string,mixed>}
     */
    private function resolvePath(string $path, array $roots, bool $allowHidden): array
    {
        $isAbsolute = str_starts_with($path, '/');


        if ($isAbsolute) {
            $real = realpath($path);
            if ($real === false) {
                return [
                    'error' => 'absolute_path_not_found',
                    'details' => [
                        'path' => $path,
                    ],
                ];
            }

            foreach ($roots as $root) {
                $rootReal = realpath($root);
                if ($rootReal === false) {
                    continue;
                }
                if (!$this->isWithinRoot($real, $rootReal)) {
                    continue;
                }
                if (!$allowHidden) {
                    $relative = ltrim(substr($real, strlen($rootReal)), '/');
                    if ($this->isHiddenPath($relative)) {
                        return [
                            'error' => 'hidden_path_forbidden',
                            'details' => [
                                'path' => $path,
                                'relative' => $relative,
                                'root' => $rootReal,
                                'allow_hidden' => $allowHidden,
                            ],
                        ];
                    }
                }
                if (!is_file($real) || !is_readable($real)) {
                    return [
                        'error' => 'unreadable_file',
                        'details' => [
                            'path' => $path,
                            'realpath' => $real,
                        ],
                    ];
                }
                return ['realpath' => $real, 'relative' => $real];
            }

            return [
                'error' => 'absolute_path_outside_roots',
                'details' => [
                    'path' => $path,
                    'realpath' => $real,
                    'roots' => $roots,
                ],
            ];
        }

        $relative = ltrim($path, '/');
        if (!$allowHidden && $this->isHiddenPath($relative)) {
            return [
                'error' => 'hidden_path_forbidden',
                'details' => [
                    'path' => $path,
                    'relative' => $relative,
                    'allow_hidden' => $allowHidden,
                ],
            ];
        }

        $attempts = [];
        foreach ($roots as $root) {
            $candidate = rtrim($root, '/') . '/' . $relative;
            $real = realpath($candidate);
            $attempts[] = ['root' => $root, 'candidate' => $candidate, 'realpath' => $real ?: null];
            if ($real === false) {
                continue;
            }

            $rootReal = realpath($root);
            if ($rootReal === false || !$this->isWithinRoot($real, $rootReal)) {
                continue;
            }

            if (!is_file($real) || !is_readable($real)) {
                continue;
            }

            return ['realpath' => $real, 'relative' => $relative];
        }

        return [
            'error' => 'relative_path_not_found',
            'details' => [
                'path' => $path,
                'relative' => $relative,
                'roots' => $roots,
                'attempts' => $attempts,
            ],
        ];
    }

    private function isWithinRoot(string $realPath, string $rootReal): bool
    {
        $root = rtrim($rootReal, '/');
        return $realPath === $root || str_starts_with($realPath, $root . '/');
    }

    private function isHiddenPath(string $relativePath): bool
    {
        $parts = array_filter(explode('/', $relativePath), static fn(string $p) => $p !== '');
        foreach ($parts as $part) {
            if (str_starts_with($part, '.')) {
                return true;
            }
        }

        return false;
    }

    private function jsonError(string $error, int $status, ?array $details = null): JsonResponse
    {
        $payload = ['ok' => false, 'error' => $error];
        if ($details !== null) {
            $payload['details'] = $details;
        }

        return new JsonResponse($payload, $status);
    }
}
