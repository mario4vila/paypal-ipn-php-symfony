# Implementación IPN Paypal en Symfony

Se recomienda instalar CLI de Symfony tener PHP 8.0 y MySQL 8.0

Documentación oficial de symfony: [https://symfony.com/doc/current/setup.html](https://symfony.com/doc/current/setup.html)

- Instalar dependencias
```
composer install
```

- Modificar el archivo .env la conexión a base de datos y configuración Paypal

- Crear base de datos
```
php bin/console doctrine:database:create
```

- Ejecutar migraciones para crear tablas
```
php bin/console doctrine:migrations:migrate
```

- Iniciar servidor symfony
```
symfony server:start -d
```

Entrar a la app en: [https://127.0.0.1:8000](https://127.0.0.1:8000)