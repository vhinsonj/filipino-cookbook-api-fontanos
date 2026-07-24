# Secured Filipino Cookbook API

A RESTful API built with the Slim Framework for retrieving and managing Filipino food recipes, categories, and ingredients. 

## Repository Contents
- Complete Slim Framework API source code
- Exported SQL database file (`database/filipino_foods_relational.sql`)
- Thunder Client testing screenshots

## Prerequisites
- XAMPP (or any local PHP/MySQL server)
- Composer installed globally

## Configuration Instructions
1. **Clone the repository:**
   `git clone https://github.com/USERNAME/filipino-cookbook-api-surname.git`
2. **Install Dependencies:**
   Open the terminal in the project folder and run: `composer install`
3. **Database Setup:**
   - Open phpMyAdmin.
   - Create a new database named `filipino_cookbook_api`.
   - Import the `database/filipino_foods_relational.sql` file into this database.
4. **Configure Credentials:**
   - Open `public/index.php`.
   - Update the `$dbuser` and `$dbpass` variables in the `getDB()` function with your local database credentials (do not commit actual passwords to GitHub).

## API Documentation
All secured endpoints require the following HTTP Header for access:
`Authorization: Bearer dmmmsu-cookbook-token-2026`

### Endpoints
* **GET `/`** (Public) - Welcome message
* **GET `/api/foods`** (Secured) - Retrieve all Filipino foods with categories, origins, and ingredients
* **GET `/api/foods/{id}`** (Secured) - Retrieve a specific food by its ID
* **GET `/api/foods/search/{name}`** (Secured) - Search for foods by name
* **GET `/api/categories`** (Secured) - Retrieve all categories
* **GET `/api/ingredients`** (Secured) - Retrieve all ingredients
* **POST `/api/foods`** (Secured) - Add a new food record