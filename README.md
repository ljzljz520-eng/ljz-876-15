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
3. 试卷管理：试卷创建、编辑、题目关联、是否开启入场核验开关。
4. 在线考试：开始考试、提交答卷、自动评分。
5. 成绩统计：个人成绩与管理端统计数据。
6. 入场身份核验：证件照上传 + 摄像头人脸采集，自动比对后分为"通过 / 疑似 / 失败"三种状态；疑似由监考老师人工确认，仅通过（或人工确认通过）的学生可进入考试。

### 身份核验与隐私保护
- **三态状态机**：`passed`（机器通过）、`suspected`（疑似，进入监考队列等待人工确认）、`failed`（失败，学生可重新提交）；人工复核结果为 `approved` / `rejected`。
- **准入闸门**：`exams/{paper}/start` 与拉取试题接口都会校验是否存在有效放行记录，未核验、疑似待确认、人工拒绝、失败均无法开考。
- **材料用途限定**：证件照与人脸照保存在 Laravel 私有磁盘（`storage/app/identity-verifications/`，非 public 目录），仅与"本次考试"关联；前端拿不到任何真实存储路径。
- **禁止后台随意浏览**：监考端查看材料必须走鉴权接口 `GET /proctor/identity-verifications/{id}/media/{type}`，响应带 `no-store`，且**每次查看都写入审计日志**（操作人、时间、IP、UA）。
- **保留期清理**：交卷后材料保留至"考试结束 + `IDENTITY_RETENTION_DAYS`（默认 7 天）"，由调度任务 `identity:purge-expired` 每日 03:15 删除文件并把记录匿名化（清除证件号密文、姓名、路径），只留最小审计骨架；管理员可在"核验日志"页追溯。
- **证件号加密**：身份证/证件号码使用 Laravel Encrypter（AES）加密落库，接口仅返回脱敏值（如 `1101************1234`）。
- **人脸比对驱动可插拔**：`App\Services\FaceMatch\Contracts\FaceMatcher`，默认 `local` 为基于 GD 的图像相似度演示实现（**非真正人脸识别，仅限演示环境**）；生产环境在 `AppServiceProvider` 中绑定云厂商人脸核身驱动（含活体检测 + 1:1 比对）即可，阈值通过 `IDENTITY_PASS_THRESHOLD` / `IDENTITY_SUSPECT_THRESHOLD` 配置。
- **提交频率控制**：同一考生同一场考试最多提交 `IDENTITY_MAX_ATTEMPTS`（默认 5）次，疑似待确认期间禁止重复提交。
- 可用 `docker compose exec backend php artisan identity:purge-expired --dry-run` 预览到期记录。

## 角色权限
| 角色 | 可访问模块 |
|---|---|
| Student | 在线考试、我的成绩 |
| Teacher | 在线考试、我的成绩、题库管理、试卷管理 |
| Admin | 全部功能（含数据统计） |

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
初始化后包含 12 张核心表（含用户、题目、试卷、考试记录、答案记录、证件人脸核验记录、核验审计日志等）。

详见：
- `docs/Database.sql`
- `docker-compose.yml` 中 `db-init` 初始化段

## 证据目录
测试与质检证据统一放在 `evidence/`（含 `evidence/run-slot*/`）目录。

---
如需进行质检修复闭环，请配合 `qa/qc-feedback-inbox.md`、`qa/qc-fix-send-template.md`、`qa/qc-fix-loop-template.md` 使用。

