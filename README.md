[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Issues](https://img.shields.io/github/issues/tekod/WpQueue.svg)](https://github.com/tekod/WpCacheController/issues)

# WpQueue
By using queue developer can defer the processing of a time-consuming task for later time,
and thus drastically speeding up the web requests to your application.

This library is WordPress implementation of AccentPHP's Queue library.

## Usage

For beginning, you must initialize and configure your first queue manager.
In this example we will create queue that will dispatch emails. 
We can give arbitrary name to our queues, let we use "mailer" for this one.
Global function "WpQueue" will return instance of queue by its name and create is if it does not exist.  
After that we should call "init" method to configure our queue.
In this example we will store queued jobs in database using WordPress "wpdb" connection, in table "wp_wpqueue_mailer_jobs".
There are many other configuration options, but you can explore them later.
```php
$queue = WpQueue('mailer');
$queue->init([
    'Storage' => new \Tekod\WpQueue\Storage\Wpdb($wpdb, 'wpqueue_mailer_jobs'),
]);
```

Now we can add new job into queue, you have to pass name of job and job data.
In this example we want to send an email:
```php
$queue->add('SendEmail', ['To'=>'me@site.com', 'Body'=>'Hi.']);
```

To register job handler you have to connect job name with callback (callable),
just like WordPress hooks. 
In this example we will register static method for "SendEmail" job:
```php
$queue->registerJobHandler('SendEmail', [MyClass::class, 'handle']);
```

Job handler method will receive job-object as parameter.
You must execute Job->setHandled() at the end of your handler to mark job 
as handled or Job->setReleased() to mark it as failed.
Handled jobs will be removed from queue while failed jobs will be postponed.
Example of job handler:
```php
public static function handle(Job $job) {
    $data = $job->getData();
    wp_mail($data['To'], 'Subject', $data['Body']);
    $job->setHandled();
}
```

To run your queue you should execute WpQueue::run() and specify what jobs you
want to execute and few config options. 
If you specify parameter only jobs of that name will be executed, otherwise queue will execute all type of jobs.
```php
$queue->run('SendEmail');
```

Note that multiple handlers can be registered on single job name,
queue manager will pass job to first handler and if it does not mark it
as handled job will be offered to next registered handler.
Using this feature you can intercept and take over normal job execution 
in certain cases, or just monitor and log jobs if you omit to set handled status.

If a job remains unhandled queue will dispatch "orphaned job" wp-action and offer
hook listeners to handle it in "catch-all" manner.

If a job remains unhandled after that queue will dispatch "unhandled job" action 
and delete job if nobody respond. 

---

# Security issues

If you have found a security issue, please contact the author directly at office@tekod.com.

