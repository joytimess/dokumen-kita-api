<?php

namespace App\Enums;

/**
 * Lifecycle of a document version; `documents.status` mirrors the latest version.
 */
enum VersionStatus: string
{
    case Draft = 'draft';
    case Preparing = 'preparing';
    case InProgress = 'in_progress';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case RevisionRequested = 'revision_requested';
    case Superseded = 'superseded';
    case Rejected = 'rejected';
}
