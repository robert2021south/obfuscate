@echo off
chcp 65001 >nul
title PHP代码混淆自动化工具

set "DEFAULT_SRC=src"
set "DEFAULT_OUT=out"
set "DEFAULT_TARGET=D:\shared_with_linux\obfuscator"

echo ========================================
echo    PHP代码混淆和文件复制工具
echo ========================================
echo.

set /p SRC_DIR="请输入源目录 [默认:%DEFAULT_SRC%]: "
if "%SRC_DIR%"=="" set "SRC_DIR=%DEFAULT_SRC%"

set /p OUT_DIR="请输入输出目录 [默认:%DEFAULT_OUT%]: "
if "%OUT_DIR%"=="" set "OUT_DIR=%DEFAULT_OUT%"

set /p TARGET_DIR="请输入目标目录 [默认:%DEFAULT_TARGET%]: "
if "%TARGET_DIR%"=="" set "TARGET_DIR=%DEFAULT_TARGET%"

echo.
echo 配置确认:
echo   源目录: %SRC_DIR%
echo   输出目录: %OUT_DIR%
echo   目标目录: %TARGET_DIR%
echo.

choice /c YN /n /m "是否继续执行？(Y/N)"
if errorlevel 2 (
    echo 操作已取消。
    pause
    exit /b 0
)

echo [1/5] 检查源目录是否存在...
if not exist "%SRC_DIR%" (
    echo 错误: 源目录 "%SRC_DIR%" 不存在！
    pause
    exit /b 1
)

echo [2/5] 执行PHP代码混淆...
php obfuscator.php "%SRC_DIR%" "%OUT_DIR%"

if errorlevel 1 (
    echo 错误: PHP混淆执行失败！
    pause
    exit /b 1
)

echo [3/5] 检查并清理目标目录和映射文件...
if exist "%TARGET_DIR%\includes" (
    echo 删除旧的includes目录...
    rmdir /s /q "%TARGET_DIR%\includes"
)

if exist "%TARGET_DIR%\obfuscation-map.json" (
    echo 删除旧的映射文件...
    del /f /q "%TARGET_DIR%\obfuscation-map.json"
)

echo [4/5] 复制新的includes目录...
if exist "%OUT_DIR%\includes" (
    xcopy "%OUT_DIR%\includes" "%TARGET_DIR%\includes\" /E /I /Y
    echo 文件复制完成！
) else (
    echo 警告: 输出目录中未找到includes文件夹
)

echo [5/5] 复制新的映射文件...
if exist "%OUT_DIR%\obfuscation-map.json" (
    copy /Y "%OUT_DIR%\obfuscation-map.json" "%TARGET_DIR%\obfuscation-map.json"
    echo 新的映射文件复制完成！
) else (
    echo 警告: 输出目录中未找到obfuscation-map.json映射文件
)


echo ========================================
echo           操作完成！
echo ========================================
