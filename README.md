# Laravel MySQL-Workbench Model

With this project you can kickstart your models and database migrations for your Laravel 5 Project. Updates are not possible with it yet, just the start.

* Just add this project to your composer setup: **"b3nl/laravel-mwb-model": "dev-master"**
* Add our service provider to the list of your service providers in config/app.php: **b3nl\MWBModel\ServiceProvider::class**
 
Now you have access to an artisan command for parsing your MySQL-Workbench-File:

```
php artisan make:mwb-model $FILE_TO_SAVED_MODEL --pivots=$COMMA_SEPARATED_LIST_OF_YOUR_PIVOT_TABLE_NAMES
```

## Special Table Comments

You can comment your tables in the MySQL-Workbrench with an [ini-String](http://php.net/manual/de/function.parse-ini-string.php) with the following options:

```
; With this comment, this table is ignored for parsing. Leave it out, if you do not want it ignored.
ignore=true
; Name of the Laravel model 
model=Name
; Is this a pivot table? Leave it out if not.
isPivot=true
; withoutTimestamps removes the default timestamps() call for the database migrations
withoutTimestamps=true
; Ini-Array for the laravel model castings: http://laravel.com/docs/5.1/eloquent-mutators#attribute-casting
[casting]
values=array
``` 
