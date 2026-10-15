<?php

namespace Zanbug\Sdk\Support;

/**
 * The route a Laravel web request matched — name and Controller@method —
 * seen from the core SDK alone, so manual installs (no adapter) and the
 * adapter report the same thing. Read-only, never throws, Laravel 5.5+ / PHP 7.0+.
 */
class LaravelRoute
{
    /**
     * @return array|null  ('name' => string, 'action' => string), either may be absent
     */
    public static function current()
    {
        if (!class_exists('\Illuminate\Container\Container', false)) {
            return null;
        }
        try {
            $app = \Illuminate\Container\Container::getInstance();
            if (!is_object($app) || !$app->bound('request')) {
                return null;
            }
            $request = $app['request'];
            if (!is_object($request) || !method_exists($request, 'route')) {
                return null;
            }
            $route = $request->route();
            if (!is_object($route)) {
                return null;
            }
            $out = array();
            if (method_exists($route, 'getName')) {
                $name = $route->getName();
                if (is_string($name) && $name !== '') {
                    $out['name'] = substr($name, 0, 200);
                }
            }
            if (method_exists($route, 'getActionName')) {
                $action = $route->getActionName();
                if (is_string($action) && $action !== '') {
                    $out['action'] = substr($action, 0, 300);
                }
            }
            if (method_exists($route, 'uri') && empty($out['name'])) {
                // Unnamed route: the URI pattern still groups /orders/1 and /orders/2.
                $uri = $route->uri();
                if (is_string($uri) && $uri !== '') {
                    $out['uri'] = substr($uri, 0, 200);
                }
            }

            return empty($out) ? null : $out;
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
