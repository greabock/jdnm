<?php

namespace Greabock\RestMapper;

use Doctrine\Common\Proxy\Exception\InvalidArgumentException;
use Doctrine\ORM\Mapping\ClassMetadataInfo as MetaInfo;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\EntityManager;

use Greabock\RestMapper\Annotations\Keeper;
use Greabock\RestMapper\Annotations\Validation;
use Greabock\RestMapper\Exceptions\PermissionDeniedException;
use Greabock\RestMapper\Exceptions\ValidationException;

use RuntimeException;

/**
 * Class Mapper
 * @package App\Services
 */
class Mapper
{
    const SETTER_PREFIX = 'set';

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var callable
     */
    protected $factory;

    /**
     * @var AnnotationReader
     */
    protected $reader;

    /**
     * Mapper constructor.
     *
     * @param EntityManager    $em
     * @param AnnotationReader $reader
     * @param                  $validator
     * @param callable         $keeper
     * @param callable         $factory
     */
    public function __construct(EntityManager $em, AnnotationReader $reader, callable $factory, callable $keeper = null, $validator = null)
    {
        $this->em = $em;
        $this->reader = $reader;
        $this->validator = $validator;
        $this->factory = $factory;
        $this->keeper = $keeper;
    }

    public function json(string $name, $data)
    {
        $properties = json_decode($data, true);

        return $this->map($name, $properties);
    }

    /**
     * @param string $name Имя класса, с которого будет начинаться мапа.
     * @param array  $data Массив данных коротрые нужно размапить.
     * @param null   $id   Идентификатор ресурса, который будет маппится.
     *
     * @return mixed|null|object
     */
    public function map(string $name, array $data, $id = null)
    {
        // Получаем метаданные класса, для которого будет происходить миаппинг.
        $meta = $this->em->getClassMetadata($name);

        // Получаем имя поля-идентификатора.
        $identifier = array_first($meta->getIdentifier());

        // Если передан идентификатор, и он не соответствует идентификатору в  массиве
        if ((!is_null($id) && isset($data[$identifier])) && $id !== $data[$identifier]) {
            throw new InvalidArgumentException('Wrong identifier');
        }

        // Если передан идентификатор - оставляем,
        // иначе - ищем в массиве.
        $id = $id ?? array_get($data, $identifier, null);

        // Устанавливаем идентификатор в массив данных.
        $data[$identifier] = array_get($data, $identifier, $id);

        // Строим по данным карту сущности и возвращаем ее.
        return $this->buildEntityMap($meta, $data, $identifier);
    }

    /**
     * TODO: распилить на части.
     *
     * @param MetaInfo $meta
     * @param array    $data
     * @param string   $identifier
     *
     * @return mixed|null|object
     */
    protected function buildEntityMap(MetaInfo $meta, array $data, $identifier)
    {
        $id = array_get($data, $identifier, null);

        // Получаем сущность.
        $entity = $this->resolveEntity($meta->getName(), $id);

        if ($this->keeper) {
            $data = $this->passThroughGates($entity, $data, $meta);
        }

        // Получаем из массива, только те данные которые относятся к полям сущности.
        $entityFields = array_only($data, $meta->getFieldNames());

        // Валидируем поля.
        if ($this->validator) {
            $this->validate($meta, $meta->getFieldNames(), $entityFields, $entity);
        }

        // Получаем из массива, только те данные которые относятся к релейшенам.
        $relationsMaps = array_only($data, $meta->getAssociationNames());

        // Валидируем релейшены.
        // Валидируем поля.
        if ($this->validator) {
            $this->validate($meta, $meta->getAssociationNames(), $relationsMaps, $entity);
        }


        // Оперделяем массив сеттеров полей.
        $setters = [];

        // Собираем из массива полей сеттеры вида:
        // ['имя метода' => 'передаваемое значение'].
        foreach ($entityFields as $name => $value) {
            $setterName = $this->getSetterName($name);
            if (!method_exists($entity, $setterName) || $name === $meta->getSingleIdentifierFieldName()) {
                continue;
            }
            $setters[$setterName] = $value;
        }

        // Собираем из массива релейшенов сеттеры вида:
        // ['имя метода' => 'передаваемое значение'].
        foreach ($relationsMaps as $name => $map) {
            $setterName = $this->getSetterName($name);

            if (!method_exists($entity, $setterName)) {
                continue;
            }

            $mapping = $meta->getAssociationMapping($name);

            // Маппим отношение в значение для сеттера.
            $setters[$setterName] =
                $this->mapRelation($mapping['type'], $mapping['targetEntity'], $map);
        }

        // Заполняем поля используя сеттеры, и возвращаем результат.
        return $this->fill($entity, $setters);
    }

    protected function resolveEntity($entity, $id = null)
    {
        // Если не установлен идентификаор
        if (!is_null($id)) {
            $identity = $this->em->find($entity, $id);
            if (is_null($identity)) {
                throw new RuntimeException(
                    "Entity {$entity} with identifier '{$id}' not found"
                );
            }

            return $identity;
        }

        return call_user_func($this->factory, $entity);
    }

    protected function isToMany($type)
    {
        return $type & (MetaInfo::ONE_TO_MANY | MetaInfo::MANY_TO_MANY);
    }

    protected function mapMany($name, $data)
    {
        return array_map(function ($item) use ($name) {
            return $this->map($name, $item);
        }, $data);
    }

    protected function mapRelation($type, $targetEntity, $map)
    {
        // Если отношение подразумевает много сущностей, то обрабатываем их как список.
        if ($this->isToMany($type)) {
            return $this->mapMany($targetEntity, $map);
        }

        return $this->map($targetEntity, $map);
    }

    protected function fill($entity, $setters)
    {
        foreach ($setters as $setter => $value) {
            call_user_func([$entity, $setter], $value);
        }

        return $entity;
    }

    public function getSetterName($name)
    {
        return static::SETTER_PREFIX . studly_case($name);
    }

    protected function validate(MetaInfo $meta, $fields, $data, $entity)
    {
        $rules = [];

        foreach ($fields as $name) {
            $val = $this->reader->getPropertyAnnotation(
                $meta->getReflectionProperty($name), Validation::class
            );

            if (is_object($val)) {
                $name .= $val->sub;
            }

            $rules[$name] = $this->extractValidationRule($val, $meta, $data, $entity);
        }

        $rules = array_filter($rules);

        $validator = $this->validator->make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    protected function extractValidationRule($val, MetaInfo $meta, $data, $entity)
    {
        if (!is_null($val)) {
            return $this->prepare($val->rule, $meta, $data, $this->em->contains($entity));
        }

        return null;
    }

    protected function prepare($rule, MetaInfo $meta, $data, $exists)
    {
        // 'static.entity'  - имя сущности
        $rule = str_replace('{static.entity}', $meta->getName(), $rule);

        // 'this.identifier' - идентификатор сущности
        $rule = str_replace('{this.identifier}', array_get($data, $meta->getSingleIdentifierFieldName(), 'NULL'), $rule);

        // 'static.identifier' - имя поля-идентификатора
        $rule = str_replace('{static.identifier}', $meta->getSingleIdentifierFieldName(), $rule);

        // если сущность уже существует - определяем правило как валидируемое, если оно представлено
        if ($exists) {
            $rule = str_replace('required', 'sometimes|required', $rule);
        }

        return $rule;
    }

    protected function passThroughGates($entity, $data, MetaInfo $meta)
    {
        $fields = array_filter(array_keys($data), function ($field) use ($meta, $entity) {
            $gate = $this->reader->getPropertyAnnotation(
                $meta->getReflectionProperty($field), Keeper::class
            );

            if (is_object($gate)) {
                if (!($this->keeper)($gate->ability, $entity)) {
                    if ($gate->strategy === Keeper::ON_FAILS_IGNORE) {
                        return null;
                    }

                    throw new PermissionDeniedException($entity, $field, $gate->ability);
                }
            }

            return $field;
        });

        return array_only($data, $fields);
    }
}