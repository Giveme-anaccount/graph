<?php
class UserController extends AbstractController
{
    public static function getUserRegex()
    {
        return '[0-9a-zA-Z_-]{2,}';
    }

    public static function parseRequest($url, &$controllerContext)
    {
        $userRegex = self::getUserRegex();
        $modulesRegex = self::getAvailableModulesRegex();
        $mediaParts = array_map(['Media', 'toString'], Media::getConstList());
        $mediaRegex = implode('|', $mediaParts);

        $regex =
            '^/?' .
            '(' . $userRegex . ')' .
            '(' . $modulesRegex . ')' .
            '(,(' . $mediaRegex . '))?' .
            '/?($|\?)';

        if (!preg_match('#' . $regex . '#', $url, $matches))
        {
            return false;
        }

        $controllerContext->userName = $matches[1];
        $media = !empty($matches[4]) ? $matches[4] : 'anime';
        switch ($media)
        {
            case 'anime': $controllerContext->media = Media::Anime; break;
            case 'manga': $controllerContext->media = Media::Manga; break;
            default: throw new BadMediaException();
        }
        $rawModule = ltrim($matches[2], '/') ?: 'profile';
        $controllerContext->rawModule = $rawModule;
        $controllerContext->module = self::getModuleByUrlPart($rawModule);
        assert(!empty($controllerContext->module));
        return true;
    }

    public static function preWork(&$controllerContext, &$viewContext)
    {
        $controllerContext->cache->setPrefix($controllerContext->userName);
        if (BanHelper::getUserBanState($controllerContext->userName) == BanHelper::USER_BAN_TOTAL)
        {
            $controllerContext->cache->bypass(true);
            $viewContext->userName = $controllerContext->userName;
            $viewContext->viewName = 'error-user-blocked';
            $viewContext->meta->title = 'User blocked - ' . Config::$title;
            $viewContext->meta->noIndex = true;
            return;
        }

        $module = $controllerContext->module;
        HttpHeadersHelper::setCurrentHeader('Content-Type', $module::getContentType());
        $viewContext->media = $controllerContext->media;
        $viewContext->module = $controllerContext->module;
        $viewContext->contentType = $module::getContentType();
        if ($viewContext->contentType != 'text/html')
        {
            $viewContext->layoutName = 'layout-raw';
        }

        Database::selectUser($controllerContext->userName);
        $user = R::findOne('user', 'LOWER(name) = LOWER(?)', [$controllerContext->userName]);
        if (empty($user)) {
            if (!isset($_GET['referral'])) {
                $controllerContext->cache->bypass(true);

                $viewContext->userName = $controllerContext->userName;

                $viewContext->viewName = 'error-user-not-found';

                $viewContext->meta->title = 'User not found &#8212; ' . Config::$title;

                $viewContext->meta->noIndex = true;

                return;
            }

            $queue = new Queue(Config::$userQueuePath);
            $queueMedia = new Queue(Config::$userMediaQueuePath);
            $queueItem = new QueueItem(strtolower($controllerContext->userName));
            $queue->enqueue($queueItem);
            $queueMedia->enqueue($queueItem);
            $viewContext->queuePosition = $queue->seek($queueItem);

            $controllerContext->cache->bypass(true);
            //try to load cache, if it exists
            $url = $controllerContext->url;
            if ($controllerContext->cache->exists($url))
            {
                $controllerContext->cache->load($url);
                flush();
                $viewContext->layoutName  = null;
                $viewContext->viewName = null;
                return;
            }
            $viewContext->userName = $controllerContext->userName;
            $viewContext->viewName = 'error-user-enqueued';
            $viewContext->meta->title = 'User enqueued - ' . Config::$title;
            return;
        }

        $viewContext->user = $user;
        $viewContext->meta->styles []= '/media/css/menu.css';
        $viewContext->updateWait = Config::$userQueueMinWait;

        $module = $controllerContext->module;
        $module::preWork($controllerContext, $viewContext);
    }

    public static function work(&$controllerContext, &$viewContext)
    {
        assert(!empty($controllerContext->module));
        if (!empty($viewContext->user))
        {
            $module = $controllerContext->module;
            $module::work($controllerContext, $viewContext);
            $viewContext->userMenu = true;
        }
    }
}
