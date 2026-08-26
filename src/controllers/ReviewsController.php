<?php

namespace typedef\contentreview\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use Throwable;
use typedef\contentreview\ContentReviewPlugin;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

class ReviewsController extends Controller
{
    /**
     * Marks an entry as reviewed (does not save the entry itself directly)
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException
     * @throws InvalidConfigException
     */
    public function actionMarkAsReviewed(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();

        $elementId = $request->getRequiredBodyParam('elementId');
        $siteId = $request->getRequiredBodyParam('siteId');

        if (!is_numeric($elementId) || !is_numeric($siteId)) {
            throw new BadRequestHttpException('Invalid entry or site ID.');
        }

        $elementId = (int)$elementId;
        $siteId = (int)$siteId;

        $entry = Entry::find()
            ->id($elementId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->revisions(null)
            ->one();

        if (!$entry) {
            throw new BadRequestHttpException('Entry not found.');
        }

        try {
            $marked = ContentReviewPlugin::getInstance()->getReviewService()->markEntryAsReviewed($entry);
            if (!$marked) {
                throw new ForbiddenHttpException('Entry cannot be marked as reviewed.');
            }
        } catch (ForbiddenHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Craft::error(
                'Could not mark entry as reviewed: ' . $exception->getMessage(),
                __METHOD__
            );

            return $this->asJson([
                'success' => false,
                'message' => Craft::t('content-review', 'Could not mark entry as reviewed.'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('content-review', 'Entry marked as reviewed.'),
        ]);
    }
}
