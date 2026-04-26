<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function parse($response)
    {
        $response = json_decode($response->getContent());

        if (isset($response->errors)) {
            return $response;
        } elseif (isset($response->exception)) {
            $this->fail(sprintf(
                'Response exception: %s in %s:%s',
                $response->message ?? 'unknown',
                $response->file ?? '?',
                $response->line ?? '?'
            ));
        }

        return $response;
    }
}
