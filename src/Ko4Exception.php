<?php
declare(strict_types=1);

namespace Ko4;

class Ko4Exception extends \RuntimeException {}
class PackageNotFoundException extends Ko4Exception {}
class DependencyException extends Ko4Exception {}
class BuildException extends Ko4Exception {}
class DatabaseException extends Ko4Exception {}
class RepoException extends Ko4Exception {}
class PermissionException extends Ko4Exception {}
class IntegrityException extends Ko4Exception {}
