#!/usr/bin/env bash

ngrok http --host-header=rewrite --request-header-remove 'referer' klms.local:80