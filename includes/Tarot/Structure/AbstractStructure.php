<?php

namespace Tarot\Structure;

use JsonSerializable;
use ReflectionClass;

/**
 * Abstract class AbstractStructure
 * 
 * This abstract class implements the JsonSerializable interface and provides
 * a generic constructor and methods for setting properties and serializing 
 * the object to JSON.
 */
abstract class AbstractStructure implements JsonSerializable
{
    /**
     * Cache of property names per class to avoid repeated reflection.
     *
     * @var array<class-string, list<string>>
     */
    private static array $propertyCache = [];

    /**
     * Constructor method
     *
     * @param array<string,mixed> $params An associative array of properties to set on the object.
     */
    public function __construct(array $params = [])
    {
        $this->setProperties($params);
    }

    /**
     * Set properties method
     *
     * This method sets the properties of the object based on the provided associative array.
     *
     * @param array<string,mixed> $params An associative array of properties to set on the object.
     * @return void
     */
    public function setProperties(array $params = []): void
    {
        foreach ($params as $property => $value) {
            if (property_exists($this, $property)) {
                $this->$property = $value;
            }
        }
    }

    /**
     * JSON serialize method
     * 
     * This method caches ReflectionClass property names per class and
     * returns an associative array of the object's properties.
     *
     * @return array<string,mixed> An associative array of the object's properties.
     */
    public function jsonSerialize(): array
    {
        $class = static::class;

        if (!isset(self::$propertyCache[$class])) {
            $reflection_class = new ReflectionClass($this);
            self::$propertyCache[$class] = array_map(
                fn($prop) => $prop->getName(),
                $reflection_class->getProperties()
            );
        }

        $data = [];
        foreach (self::$propertyCache[$class] as $name) {
            $data[$name] = $this->$name;
        }

        return $data;
    }
}
