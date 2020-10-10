# 5 Topics
[http://www.rabbitmq.com/tutorials/tutorial-five-php.html](http://www.rabbitmq.com/tutorials/tutorial-five-php.html)


```
php receive_logs_topic-async.php start "#"
php receive_logs_topic-async.php start "kern.*"
php receive_logs_topic-async.php start "*.critical"
php receive_logs_topic-async.php start "kern.*" "*.critical"
```

```
php emit_log_topic-async.php start
```