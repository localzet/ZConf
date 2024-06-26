<p align="center">
  <a href="https://www.localzet.com" target="_blank">
    <img src="https://cdn.localzet.com/assets/media/logos/ZorinProjectsSP.svg" width="400">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/localzet/zconf">
  <img src="https://img.shields.io/packagist/dt/localzet/zconf?label=%D0%A1%D0%BA%D0%B0%D1%87%D0%B8%D0%B2%D0%B0%D0%BD%D0%B8%D1%8F" alt="Скачивания">
</a>
  <a href="https://github.com/localzet/zconf">
  <img src="https://img.shields.io/github/commit-activity/t/localzet/zconf?label=%D0%9A%D0%BE%D0%BC%D0%BC%D0%B8%D1%82%D1%8B" alt="Коммиты">
</a>
  <a href="https://packagist.org/packages/localzet/zconf">
  <img src="https://img.shields.io/packagist/v/localzet/zconf?label=%D0%92%D0%B5%D1%80%D1%81%D0%B8%D1%8F" alt="Версия">
</a>
  <a href="https://packagist.org/packages/localzet/zconf">
  <img src="https://img.shields.io/packagist/dependency-v/localzet/zconf/php?label=PHP" alt="Версия PHP">
</a>
  <a href="https://github.com/localzet/zconf">
  <img src="https://img.shields.io/github/license/localzet/zconf?label=%D0%9B%D0%B8%D1%86%D0%B5%D0%BD%D0%B7%D0%B8%D1%8F" alt="Лицензия">
</a>
</p>

Zorin Configuration Language (ZCONF) Utils
===================

PHP парсер для ZCONF, совместимый с [TOML v0.4.0](https://github.com/toml-lang/toml/releases/tag/v0.4.0).

Установка
------------
**Требуется PHP >= 7.1.**

Используйте [Composer](http://getcomposer.org/) для установки этого пакета:

```bash
composer require localzet/zconf
```

Использование
-----
Вы можете разобрать как простую строку ZCONF, так и файл `.zconf`:

Парсинг простой строки ZCONF:

```php
use ZCONF\Parser;

$array = Parser::parseString('key = [1,2,3]');

print_r($array);
```

Парсинг файла `.zconf`:

```php
use ZCONF\Parser;

$array = Parser::parseFile('example.zconf');

print_r($array);
```

Кроме того, методы `parseString` и `parseFile` принимают второй аргумент под названием
`resultAsObject` для возврата результата в виде объекта на основе `stdClass`.

```php
$object = Parser::parseString('key = [1,2,3]', true);
```

### Генератор
Вы можете сгенерировать строку ZCONF с помощью класса `Builder`.
Генератор использует *fluent interface* для более читаемого кода:

```php
    use ZCONF\Builder;

    $tb = new Builder();

    $result = $tb->addComment('ZCONF file')
        ->addTable('data.string')
        ->addValue('name', "Ivan", 'Ваше имя, например')
        ->addValue('newline', "Эта строка содержит \n символ новой строки")
        ->addValue('winPath', "C:\\Users\\nodejs\\templates")
        ->addValue('literal', '@<\i\c*\s*>') // Строки, начинающиеся с '@'.
        ->addValue('unicode', 'Символ юникода: ' . json_decode('"\u03B4"'))

        ->addTable('data.bool')
        ->addValue('t', true)
        ->addValue('f', false)

        ->addTable('data.integer')
        ->addValue('positive', 25, 'Встроенный комментарий')
        ->addValue('negative', -25)

        ->addTable('data.float')
        ->addValue('positive', 25.25)
        ->addValue('negative', -25.25)

        ->addTable('data.datetime')
        ->addValue('datetime', new \Datetime())

        ->addComment('Работа с массивами')

        ->addTable('data.array')
        ->addValue('simple', array(1,2,3))
        ->addValue('multiple', array(
            array(1,2),
            array('abc', 'def'),
            array(1.1, 1.2),
            array(true, false),
            array( new \Datetime()) ))

        ->addComment('Массивы таблиц')

        ->addArrayOfTable('fruit')                            // Строка
            ->addValue('name', 'apple')
            ->addArrayOfTable('fruit.variety')
                ->addValue('name', 'red delicious')
            ->addArrayOfTable('fruit.variety')
                ->addValue('name', 'granny smith')
        ->addArrayOfTable('fruit')                            // Строка
            ->addValue('name', 'banana')
            ->addArrayOfTable('fruit.variety')
                ->addValue('name', 'plantain')
        ->getString();    // Сгенерировать строку ZCONF
```
Результат:

```toml
# Файл ZCONF

[data.string]
name = "ZCONF" # Ваше имя, например
newline = "Эта строка содержит \n символ новой строки"
winPath = "C:\\Users\\nodejs\\templates"
literal = '<\i\c*\s*>'
unicode = "Символ юникода: δ"

[data.bool]
t = true
f = false

[data.integer]
positive = 25 # Встроенный комментарий
negative = -25

[data.float]
positive = 25.25
negative = -25.25

[data.datetime]
datetime = 2024-06-26T21:12:48Z

# Работа с массивами

[data.array]
simple = [1, 2, 3]
multiple = [[1, 2], ["abc", "def"], [1.1, 1.2], [true, false], [2024-06-26T21:12:48Z]]

# Массивы таблиц

[[fruit]]
name = "apple"

[[fruit.variety]]
name = "red delicious"

[[fruit.variety]]
name = "granny smith"

[[fruit]]
name = "banana"

[[fruit.variety]]
name = "plantain"
```
#### Ограничения
Класс  `Builder` - это утилита для получения строк ZCONF, которая имеет следующие ограничения:
* Принимает только `базовые строки` и `литеральные строки`.

## Лицензия

Эта библиотека является программным обеспечением с открытым исходным кодом, лицензированным в соответствии с
[лицензией MIT](http://opensource.org/licenses/MIT).
