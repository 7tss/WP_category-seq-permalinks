# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.0] - 2025-01-XX

### Added
- 動的リライトルールの実装
- 410 Gone による不要URLの自然消滅促進機能
- 包括的なエラーハンドリングシステム
- 削除時クリーンアップ機能の強化
- デバッグ用ログ記録機能

### Changed
- リライトルールを許可カテゴリのみに限定
- 404エラー処理の改善
- リダイレクト処理の最適化

### Fixed
- 存在しないカテゴリへの不要なマッチングを防止
- パフォーマンスの向上
- データベースの整合性維持

## [1.4.0] - 2025-01-XX

### Added
- 404エラー対応の改善
- リダイレクト処理の追加
- 削除時の処理を実装

### Fixed
- WordPressの標準URLとの競合問題を解決
- カテゴリ別連番URLの適切な処理

## [1.3.0] - 2025-01-XX

### Added
- 基本的なカテゴリ別連番機能
- 手動連番設定機能
- 主要カテゴリ指定機能
- スラッグ置換機能

### Features
- `/カテゴリ名/連番/` 形式のパーマリンク生成
- 重複時の自動調整機能
- 管理画面でのメタボックス提供
