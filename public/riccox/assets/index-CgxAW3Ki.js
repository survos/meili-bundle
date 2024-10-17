const n={label:"字段分布",tip:"仅展示前十个字段，按分布排序",subtitle:"点我转到有关 Field Distribution 的官方文档"},t="文档计数",e="索引中",i="此索引正在为文档编制索引，设置与搜索结果现在可能显示不正确!",o={dialog:{content:`您正在<strong>删除索引{{uid}}</strong>。
此操作非常重要，需要您确认。
请点击以下按钮之一继续。`,title:"请确认您的操作"},label:"删除此索引"},s={dialog:{content:`您正在<strong>删除索引{{uid}}的所有文档</strong>。
此操作非常重要，需要您确认。
请点击以下按钮之一继续。`,title:"请确认您的操作"},label:"删除所有文档"},l="主键",d="未找到索引",c={index:{config:{label:"索引配置"},danger_zone:"危险区"}},a={tip:"此处仅搜索本页所展示的数据，这是因为 Meilisearch 并未提供对索引列表的搜索接口！"},r={fieldDistribution:n,count:t,indexing:e,indexing_tip:i,index_delete:o,all_documents_delete:s,primaryKey:l,not_found:d,setting:c,search:a};export{s as all_documents_delete,t as count,r as default,n as fieldDistribution,o as index_delete,e as indexing,i as indexing_tip,d as not_found,l as primaryKey,a as search,c as setting};
