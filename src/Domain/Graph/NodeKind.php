<?php

namespace DNDark\LogicMap\Domain\Graph;

enum NodeKind: string
{
    case File = 'file';
    case ClassSymbol = 'class';
    case InterfaceSymbol = 'interface';
    case TraitSymbol = 'trait';
    case EnumSymbol = 'enum';
    case Method = 'method';
    case Route = 'route';
    case Middleware = 'middleware';
    case FormRequest = 'form_request';
    case Policy = 'policy';
    case Controller = 'controller';
    case Action = 'action';
    case Service = 'service';
    case Repository = 'repository';
    case Command = 'command';
    case Schedule = 'schedule';
    case Job = 'job';
    case Event = 'event';
    case Listener = 'listener';
    case Notification = 'notification';
    case Mailable = 'mailable';
    case Model = 'model';
    case Table = 'table';
    case Column = 'column';
    case CacheKey = 'cache_key';
    case ConfigKey = 'config_key';
    case StoragePath = 'storage_path';
    case ExternalEndpoint = 'external_endpoint';
    case View = 'view';
    case Module = 'module';
    case Process = 'process';
    case Decision = 'decision';
    case Test = 'test';
    case Unknown = 'unknown';
}
