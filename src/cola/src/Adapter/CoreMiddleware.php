<?php

declare(strict_types=1);

namespace MaliBoot\Cola\Adapter;

use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Jsonable;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Utils\Arr;
use MaliBoot\ApiAnnotation\ApiParam;
use MaliBoot\Dto\AbstractDTO;
use MaliBoot\Dto\UserContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CoreMiddleware extends \Hyperf\HttpServer\CoreMiddleware
{
    /**
     * Parse the parameters of method definitions, and then bind the specified arguments or
     * get the value from DI container, combine to a argument array that should be injected
     * and return the array.
     */
    protected function parseMethodParameters(string $controller, string $action, array $arguments): array
    {
        $definitions = $this->getMethodDefinitionCollector()->getParameters($controller, $action);
        return $this->getInjections($definitions, "{$controller}::{$action}", $arguments);
    }

    /**
     * Transfer the non-standard response content to a standard response object.
     *
     * @param null|array|Arrayable|Jsonable|string $response
     */
    protected function transferToResponse($response, ServerRequestInterface $request): ResponseInterface
    {
        // TODO 是否增加一个配置项更好？
        if (interface_exists(\MaliBoot\ResponseWrapper\Contract\ResponseWrapperInterface::class)) {
            $responseWrapper = make(\MaliBoot\ResponseWrapper\Contract\ResponseWrapperInterface::class);
            $response = $responseWrapper->handle($response, $request);
        }

        return parent::transferToResponse($response, $request);
    }

    protected function isAnnotationParam(string $callableName, string $name): bool
    {
        [$className, $methodName] = explode('::', $callableName);
        $methodAnnotations = AnnotationCollector::getClassMethodAnnotation($className, $methodName);
        foreach ($methodAnnotations as $methodAnnotation) {
            if ($methodAnnotation instanceof ApiParam && $methodAnnotation->name === $name) {
                return true;
            }
        }
        return false;
    }

    protected function initDTO(string $className): AbstractDTO
    {
        $request = $this->container->get(RequestInterface::class);

        if (method_exists($className, 'fromRequest')) {
            return call_user_func([$className, 'fromRequest'], $request);
        }

        $dto = call_user_func([$className, 'of'], $request->all());
        $this->fillUserToDTO($request, $dto);

        return $dto;
    }

    protected function fillUserToDTO(RequestInterface $request, AbstractDTO $dto): AbstractDTO
    {
        if (empty($user = $request->getAttribute('user'))) {
            return $dto;
        }

        if (! $user instanceof UserContext) {
            $userContext = new UserContext();

            if ($user instanceof Arrayable
                || $user instanceof \MaliBoot\Utils\Contract\Arrayable
                || method_exists($user, 'toArray')
            ) {
                $user = $user->toArray();
            }

            $userContext->setProperties((array) $user);
        } else {
            $userContext = $user;
        }

        $dto->setUser($userContext);
        return $dto;
    }

    protected function convertType($value, string $type): mixed
    {
        return match ($type) {
            'int', 'Int', 'INT', 'integer' => (int) $value,
            'string', 'String', 'STRING' => (string) $value,
            'bool', 'boolean', 'Bool', 'Boolean', 'BOOL', 'BOOLEAN' => (bool) $value,
            'float', 'Float', 'FLOAT' => (float) $value,
            default => $value,
        };
    }

    private function getInjections(array $definitions, string $callableName, array $arguments): array
    {
        $injections = [];
        foreach ($definitions ?? [] as $pos => $definition) {
            $value = $arguments[$pos] ?? $arguments[$definition->getMeta('name')] ?? null;
            $type = $definition->getName();
            $name = $definition->getMeta('name');

            if ($value === null) {
                if ($definition->getMeta('defaultValueAvailable')) {
                    $injections[] = $definition->getMeta('defaultValue');
                } elseif ($definition->allowsNull()) {
                    $injections[] = null;
                } elseif (is_subclass_of($type, AbstractDTO::class)) {
                    $injections[] = $this->initDTO($type);
                } elseif ($this->container->has($type)) {
                    $instance = $this->container->get($type);
                    $injections[] = $instance;
                } elseif ($this->isAnnotationParam($callableName, $name)) {
                    $request = $this->container->get(RequestInterface::class);
                    $injections[] = $this->convertType(Arr::get($request->all(), $name), $type);
                } else {
                    throw new \InvalidArgumentException("Parameter '{$definition->getMeta('name')}' "
                        . "of {$callableName} should not be null");
                }
            } else {
                $injections[] = $this->getNormalizer()->denormalize($value, $definition->getName());
            }
        }
        return $injections;
    }
}