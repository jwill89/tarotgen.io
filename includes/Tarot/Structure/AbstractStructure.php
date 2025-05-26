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
     * Constructor method
     * 
     * @param array $params An associative array of properties to set on the object.
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
     * @param array $params An associative array of properties to set on the object.
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
     * This method uses ReflectionClass to get the properties of the object and 
     * adds them to an associative array which is returned.
     * 
     * @return array An associative array of the object's properties.
     */
    public function jsonSerialize(): array
    {
        $reflection_class = new ReflectionClass($this);
        $properties = $reflection_class->getProperties();
        $data = [];

        foreach ($properties as $property) {
            $data[$property->getName()] = $property->getValue($this);
        }

        return $data;
    }
}
