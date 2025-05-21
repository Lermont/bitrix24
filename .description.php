<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true) die();

$arComponentDescription = [
    "NAME" => "Draft App",
    "DESCRIPTION" => "Типовое приложение-заготовка",
    "ICON" => "/images/icon.gif", // Путь к иконке приложения
    "SORT" => 10,
    "PATH" => [
        "ID" => "utility", // ID раздела в списке компонентов
        "CHILD" => [
            "ID" => "user",
            "NAME" => "Пользовательские"
        ]
    ],
    "CACHE_PATH" => "Y",
    "COMPLEX" => "N"
]; 