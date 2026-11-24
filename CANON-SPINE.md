# Canon chung và xương sống xuyên app — áp cho bizcity-twin-notebook (Open Notebook)

<!-- [2026-11-24 06:40 PM Johnny Chu - Chu Hoàng Anh] SPINE-3 — gieo canon theo Q-SPINE-4 (chủ duyệt 2026-11-24). Đặt ở gốc vì repo là gói chạy (R-PROD-REPO), bỏ theo dõi mọi docs/. Tài liệu thuần, không đổi mã. -->

| Tài liệu | Ở đâu |
|---|---|
| **BIZCITY OPEN CANON** | [open-hub/docs/canon](https://github.com/hoanganh8389/open-hub/blob/main/docs/canon/README.md) |
| **`bizcity_spine@1`** — xương sống xuyên app | [bizcity-platform/docs/standard/BIZCITY-SPINE-v1.md](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-SPINE-v1.md) |
| Nền | [twin-brain](https://github.com/hoanganh8389/twin-brain) (`docs/developer/CANON-SPINE.md`) |

## Open Notebook trong xương sống (`bizcity.app.json`)

| | |
|---|---|
| Làm chủ | `notebook` — ghi chú có nguồn (R-CANON-1: "Notebook: ghi chú có nguồn") |
| Dùng | `tenant`, `user` (`sub = wp:<id>`), `document` (dẫn nguồn bằng `doc_ref`, không sao chép dữ liệu nghiệp vụ) |
| Ghi vào app khác | không; chỉ đọc qua MCP của app chủ khi cần ngữ cảnh |

## Ba điều không làm

- Không giữ bản sao danh mục / sổ của app khác như nguồn sự thật.
- Không ghi `contact_id`, `item_id` tự đặt; tham chiếu bằng `*Ref` của chủ.
- Không thêm trục mới ở repo này.

## Kiểm

```bash
node ../bizcity-platform/tools/spine-check.mjs --manifest bizcity.app.json   # SC-SPINE-1, phải ĐẠT
```
