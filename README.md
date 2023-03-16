## About

Forked from [thephpleague/openapi-psr7-validator](https://github.com/thephpleague/openapi-psr7-validator)


## Installation
```
composer require ensi/openapi-psr7-validator
```

## Additional

Добавлена проверка `BodySchemaValidator` на отсутствие неописанных ключей в ответе.

Для того, чтобы пропустить данную проверку, в схеме необходимо добавить ключ:

```
x-skip-response-validation: true
```

Пример:

```
Error:
  type: object
  properties:
    code:
      description: Строковый код ошибки
      type: string
    message:
      description: Описание ошибки
      type: string
    meta:
      x-skip-response-validation: true
      type: object
      description: Объект с мета-информацией
  required:
    - code
    - message
```

Все возможные рекурсии в схемах также должны сопровождаться ключем x-skip-response-validation.
