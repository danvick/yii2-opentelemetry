<?php

namespace danvick\yii2\otel;

/**
 * Interface for providing custom span attributes to the root span.
 *
 * Implement this interface to inject application-specific attributes
 * (e.g., tenant context) into root spans created by OtelBootstrap.
 *
 * Register implementations via the `spanAttributeProviders` config property
 * on OtelBootstrap.
 */
interface SpanAttributeProviderInterface
{
    /**
     * Returns an associative array of attribute key-value pairs
     * to set on the root span.
     *
     * Return an empty array when no attributes should be added.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array;
}
