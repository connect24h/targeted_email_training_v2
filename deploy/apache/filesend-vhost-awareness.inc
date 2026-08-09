# filesend SSL VirtualHost内で、既存の<Location /tet2>をこの内容へ置き換える。
# 環境変数が一致する単一endpointだけBasic認証を迂回し、API本体のBearer認証へ渡す。
SetEnvIf Request_URI "^/tet2/api/integrations/awareness_targets\.php$" tet2_awareness_api=1
# mod_auth_basicがAuthorizationを処理した後もBearer値をPHPへ渡す。
SetEnvIf Authorization "^(.*)$" HTTP_AUTHORIZATION=$1
<Location /tet2>
    AuthType Basic
    AuthName "TET v2 Restricted"
    AuthUserFile /etc/apache2/.htpasswd-tet2
    <RequireAny>
        Require env tet2_awareness_api
        Require valid-user
    </RequireAny>
</Location>

# root:root 600で配置し、version管理しない。
IncludeOptional /etc/apache2/tet2-awareness-secret.conf
