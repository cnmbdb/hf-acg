!function () {
    let table;
    const platformDict = [
        {id: "default", name: "默认平台"},
        {id: "fansgurus", name: "FansGurus 平台"},
        {id: "hstockplus", name: "HStockPlus 平台"}
    ];
    const platformCategoryDict = source => `commodity_platform_category->source=${source || "default"},id,name,pid&tree=true`;
    const isDefaultSource = source => (source || "default") === "default";
    const reloadTreeSelect = (form, name, dict = null, value = "") => {
        const item = form.form?.[name];
        if (!item) {
            return;
        }
        if (dict) {
            item.dict = dict;
        }
        item.default = value;
        $(`.${form.getUnique()} .component-${name}`).html(`<input type="text" lay-filter="${form.getUnique() + name}" class="layui-input tree-select"><input name="${name}" type="hidden" class="layui-input" value="${value ?? ""}">`);
        form.treeSelectRegister(item);
    };
    const categorySourceFromAssign = assign => {
        if (assign?.source) {
            return assign.source;
        }

        const icon = String(assign?.icon ?? '').toLowerCase();
        if (icon.includes('/app/plugin/fansgurus/assets/icons/')) {
            return 'fansgurus';
        }
        if (icon.includes('/app/plugin/hstockplus/assets/icons/')) {
            return 'hstockplus';
        }
        return 'default';
    };
    const modal = (title, assign = {}) => {
        const categorySource = categorySourceFromAssign(assign);
        const categoryLevel = isDefaultSource(categorySource) ? 'root' : (Number(assign?.pid ?? 0) > 0 ? 'child' : 'root');

        component.popup({
            submit: '/admin/api/category/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            name: "user_level_config",
                            type: "textarea",
                            hide: true
                        },
                        {
                            title: "平台来源",
                            name: "source",
                            type: "select",
                            placeholder: "请选择平台来源",
                            dict: platformDict,
                            default: categorySource,
                            required: true,
                            change: (form, value) => {
                                const source = value || "default";
                                reloadTreeSelect(form, "pid", platformCategoryDict(source));
                                if (isDefaultSource(source)) {
                                    form.setRadio("category_level", "root", true);
                                    form.hide("category_level");
                                    form.hide("pid");
                                    reloadTreeSelect(form, "pid", null, "");
                                    return;
                                }
                                form.show("category_level");
                                form.triggerOtherPopupChange("category_level", form.getData("category_level") || "root");
                            }
                        },
                        {
                            title: "分类级别",
                            name: "category_level",
                            type: "radio",
                            dict: [
                                {id: "root", name: "添加一级分类"},
                                {id: "child", name: "添加二级分类"}
                            ],
                            default: categoryLevel,
                            submit: false,
                            required: true,
                            hide: isDefaultSource(categorySource),
                            change: (form, value) => {
                                if (value === "child") {
                                    form.show("pid");
                                } else {
                                    form.hide("pid");
                                    reloadTreeSelect(form, "pid", null, "");
                                }
                            },
                            complete: (form, value) => {
                                form.triggerOtherPopupChange("category_level", value);
                            }
                        },
                        {
                            title: "一级分类",
                            name: "pid",
                            type: "treeSelect",
                            dict: platformCategoryDict(categorySource),
                            placeholder: "请选择要挂载到哪个一级分类下",
                            parent: false,
                            maxLevel: 1,
                            hide: isDefaultSource(categorySource) || categoryLevel !== "child"
                        },
                        {
                            title: "图标",
                            name: "icon",
                            type: "image",
                            placeholder: "请选择图标",
                            uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get',
                            height: 64,
                            required: true
                        },
                        {
                            title: "分类名称",
                            name: "name",
                            type: "textarea",
                            height: 38,
                            placeholder: "请输入分类名称",
                            required: true
                        },
                        {title: "排序", name: "sort", type: "input", placeholder: "值越小，排名越靠前哦~"},
                        {
                            title: "隐藏分类",
                            name: "hide",
                            type: "switch",
                            text: "是",
                            default: 0,
                            tips: "隐藏分类后，游客将看不见该分类，但你可以通过右侧的《会员等级》来进行对指定的会员等级显示。"
                        },
                        {title: "状态", name: "status", type: "switch", text: "启用"},
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-user") + " 会员等级",
                    form: [
                        {
                            name: "user",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="mcy-card"><table id="category-group-table"></table></div>`);

                                util.get("/admin/api/group/data", res => {
                                    let raw = form.getData("user_level_config");
                                    let configStr = raw ? decodeURIComponent(raw) : "{}";
                                    let config = {};

                                    try {
                                        config = JSON.parse(configStr);
                                        if (typeof config != "object") {
                                            config = {};
                                        }
                                    } catch (e) {
                                        config = {};
                                    }

                                    for (let i = 0; i < res.list.length; i++) {
                                        res.list[i]['show'] = config[res.list[i].id]?.show ? 1 : 0;
                                    }

                                    const groupTable = new Table(res.list, dom.find('#category-group-table'));

                                    groupTable.setColumns([
                                        {
                                            field: 'name',
                                            title: '会员',
                                            class: 'nowrap',
                                            formatter: (_, __) => format.group(__)
                                        },
                                        {
                                            field: 'show',
                                            title: '绝对显示',
                                            type: 'switch',
                                            text: "启用|关闭",
                                            change: (_, __) => {
                                                config[__.id] = {"show": _};
                                                form.setTextarea("user_level_config", JSON.stringify(config));
                                            }
                                        }
                                    ]);
                                    groupTable.render();
                                });
                            }
                        }
                    ]
                },
            ],
            assign: assign,
            autoPosition: true,
            height: "auto",
            width: "680px",
            done: () => {
                table.refresh();
            }
        });
    }

    table = new Table("/admin/api/category/data", "#category-table");
    table.setUpdate("/admin/api/category/save");
    table.disablePagination();
    table.setTree(3, "name", "id", "pid", false);
    table.setColumns([
        {checkbox: true},
        {field: 'icon', title: '', type: "image", style: "border-radius:25%;", width: 28},
        {
            field: 'owner', title: '创建者', formatter: (_, __) => mdOwnerCell(_)
        },
        {
            field: 'name', title: '分类名称'
        }
        , {
            field: 'level_name', title: '层级', formatter: (value, row) => {
                const className = row._level == 2 ? "badge-light-info" : "badge-light-primary";
                return `<span class="badge ${className}">${value || (row.pid > 0 ? "二级分类" : "一级分类")}</span>`;
            }
        }
        , {field: 'children_count', title: '下级数', class: "nowrap"}
        , {field: 'commodity_count', title: '商品数', class: "nowrap"}
        , {field: 'sort', title: '排序(越小越前)', sort: true, type: "input", reload: true}
        , {
            field: 'share_url', title: '推广链接', type: "button", buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-copy',
                    class: "text-primary",
                    title: "复制",
                    click: (event, value, row, index) => {
                        util.copyTextToClipboard(row.share_url, () => {
                            message.success("复制成功");
                        });
                    }
                },
            ]
        }
        , {
            field: 'hide', title: '隐藏', type: "switch", text: "隐藏|未隐藏"
        }
        , {
            field: 'status', title: '状态', type: "switch", text: "启用|停用"
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改分类", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        message.ask("是否删除此分类？", () => {
                            util.post('/admin/api/category/del', {list: [row.id]}, res => {
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        },
    ]);
    table.setSearch([
        {
            title: "平台来源",
            name: "source",
            type: "select",
            dict: platformDict,
            default: "default",
            change: () => table.reload({pageNumber: 1})
        },
        {title: "分类名称", name: "search-name", type: "input"}
    ]);
    table.setState("status", "_common_status");

    table.render();


    $('.btn-app-create').click(function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加分类`);
    });

    $('.btn-app-del').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个商品分类进行操作！");
            return;
        }

        message.ask("注意，删除分类后无法恢复", () => {
            util.post("/admin/api/category/del", {list: data}, res => {
                message.success("删除成功")
                table.refresh();
            });
        });
    });

    $('.start').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个分类进行操作！");
            return;
        }
        message.ask(null, () => {
            util.post("/admin/api/category/status", {list: data, status: 1}, res => {
                message.success("启用成功");
                table.refresh();
            });
        });
    });

    $('.stop').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个分类进行操作！");
            return;
        }
        message.ask(null, () => {
            util.post("/admin/api/category/status", {list: data, status: 0}, res => {
                message.success("停用成功");
                table.refresh();
            });
        });
    });


}();
