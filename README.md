# 在线考试题库系统（876）

## 项目类型
- 全栈 Web 项目（`frontend` + `backend`）

## 项目简介
本项目是一个基于 Vue 3 + Laravel 12 的在线考试与题库管理系统，支持多角色登录、题库管理、试卷管理、在线考试与成绩统计。

## 技术栈
### 前端
- Vue 3
- Vite
- Pinia
- Vue Router
- Axios
- TailwindCSS

### 后端
- Laravel 12（PHP 8.2）
- Laravel Sanctum（Token 鉴权）
- MySQL 8.0

### 运行方式
- Docker Compose（推荐，当前项目默认方式）

## 目录结构
```text
876/
├── docker-compose.yml
├── README.md
├── frontend/
│   ├── Dockerfile
│   ├── nginx.conf
│   ├── package.json
│   └── src/
├── backend/
│   ├── Dockerfile
│   ├── composer.json
│   ├── app/
│   └── routes/
├── docs/
│   ├── ARCHITECTURE.md
│   └── Database.sql
├── scripts/
└── evidence/
```

说明：`node_modules/`、`vendor/` 等依赖目录由 Docker 构建时自动安装，不需要打包提交。

## 启动与重建
在仓库根目录执行：

```bash
docker compose down
docker compose up -d --build
docker compose ps
```

## 服务地址
| 服务 | 地址 | 说明 |
|---|---|---|
| 前端 | http://localhost:8080 | 用户界面 |
| 后端 API | http://localhost:9000/api | Laravel API |
| MySQL | localhost:3307 | 数据库端口映射 |

## 测试账号
| 角色 | 邮箱 | 密码 |
|------|-------|----------|
| Admin | admin@example.com | password |
| Teacher | teacher@example.com | password |
| Student | student1@example.com | password |

> 登录页已移除快捷测试账号模块，请手动输入账号密码。

## README 与测试账号清单同步（必跑）
在截图前、提交前执行以下命令：

```bash
node scripts/sync-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
node scripts/verify-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
```

阻断规则：任一命令失败都应视为 `README_TEST_CREDENTIALS_MISMATCH`，不得继续提交流程。

## 核心功能
1. 用户认证：注册、登录、退出。
2. 题库管理：题目增删改查、分类管理。
3. 试卷管理：试卷创建、编辑、题目关联。
4. 在线考试：开始考试、提交答卷、自动评分。
5. 成绩统计：个人成绩与管理端统计数据。
6. 考前身份核验：证件照上传 + 摄像头人脸比对，通过/疑似/失败三态判定，疑似单由监考老师人工确认；核验材料仅用于本次考试，按保留期自动清理。

## 角色权限
| 角色 | 可访问模块 |
|---|---|
| Student | 在线考试（含考前身份核验）、我的成绩 |
| Teacher | 在线考试、我的成绩、题库管理、试卷管理、核验审核（人工确认疑似单） |
| Admin | 全部功能（含数据统计） |

## 考前身份核验（隐私合规设计）
- **核验流程**：学生进入考试前须先上传证件照片并完成摄像头人脸抓拍，系统自动比对相似度并给出三态结果：
  - `passed`（通过）：相似度 ≥ `FACE_PASS_THRESHOLD`（默认 0.85），可直接进入考试；
  - `suspicious`（疑似）：相似度介于 `FACE_SUSPICIOUS_THRESHOLD`（默认 0.60）与通过阈值之间，进入监考老师人工确认队列，确认通过后方可开考；
  - `failed`（失败）：低于疑似阈值，需重新核验。
- **材料用途限制**：证件照与人脸抓拍仅存于服务端私有存储（`storage/app/verifications`，无公开 URL）；仅"待人工确认的疑似单"可由监考老师/管理员经受控接口查看，确认完成后即关闭查看入口，学生侧不回显。
- **保留期清理**：交卷后材料保留 `VERIFICATION_RETENTION_HOURS` 小时（默认 24h）供申诉复核；未交卷兜底保留 `VERIFICATION_MAX_RETENTION_HOURS` 小时（默认 72h）。到期由 `verifications:purge` 定时命令（每小时）物理删除图片文件，数据库仅保留状态/分数等审计元数据；无 cron 环境下相关接口会惰性触发清理，已清理材料访问返回 `410`。
- **本地比对说明**：当前人脸比对为本地感知哈希演示实现（`app/Services/FaceCompareService.php`），生产环境可将其 `compare()` 替换为云厂商人脸比对 API，其余流程无需改动。

### 数据库升级说明（已有部署）
`exam_verifications` 表已加入 `docker-compose.yml` 的 `db-init` 初始化段，全新部署自动建表。已有数据卷的部署需手动执行建表 SQL（见 `docker-compose.yml` 中 `exam_verifications` 定义）后重建后端容器。


## 人工验证步骤（建议）
1. 打开登录页：`http://localhost:8080/login`。
2. 使用测试账号手动登录，确认菜单与角色权限一致。
3. 进入题库管理，验证新增/编辑/删除流程。
4. 进入试卷管理，验证题目关联与试卷删除流程。
5. 学生账号完成一次在线考试并查看成绩。
6. Admin 查看统计页数据。
7. API 冒烟：

```bash
docker compose exec backend sh -lc "curl -s -o /tmp/unauth.txt -w '%{http_code}\n' http://localhost:8080/api/exams"
docker compose exec backend sh -lc "curl -s -X POST http://localhost:8080/api/auth/login -H 'Content-Type: application/json' -d '{\"email\":\"admin@example.com\",\"password\":\"password\"}'"
```

预期：未登录访问受保护接口返回 `401`；登录接口返回包含 `token` 的 JSON。

## 安全与质量说明
- 密码为哈希存储（bcrypt）。
- API 使用 Sanctum Token 鉴权。
- 接口包含输入校验与错误处理。
- CORS 与基础限流已配置。

## 数据库说明
当前初始化后包含 11 张核心表（含用户、题目、试卷、考试记录、答案记录、身份核验记录等）。

详见：
- `docs/Database.sql`
- `docker-compose.yml` 中 `db-init` 初始化段

## 证据目录
测试与质检证据统一放在 `evidence/`（含 `evidence/run-slot*/`）目录。

---
如需进行质检修复闭环，请配合 `qa/qc-feedback-inbox.md`、`qa/qc-fix-send-template.md`、`qa/qc-fix-loop-template.md` 使用。

